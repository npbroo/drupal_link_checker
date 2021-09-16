<?php

function extract_urls_from_entities($entity_batch, $field_types, $field_map, $entities) {
  foreach ($entity_batch as $entity) {
    extract_urls_from_entity($entity, $field_types, $field_map, $entities);
  }
}

function extract_urls_from_entity($entity, $field_types, $field_map, $entities) {
  $entity_info = value_query_database($entity, $field_types, $field_map);
  $entity_info = add_alias($entity_info, $entities);
  $extracted_urls = extract_urls_from_text($entity_info);
  insert_new_links_into_database($extracted_urls);
}

function value_query_database($entity, $field_types, $field_map) {
  // queries the database for all strings that may contain links
  // these strings are attached to the different entity field types assigned in the search settings

  $entity_name = $entity['entity'];
  $field = $entity['entity_field'];
  $id = $entity['entity_id'];
  $type = $field_types[$field_map[$entity_name][$field]['type']];
  $column_name = $field.'_'.$type; //matches the format stored in the database
  $table_name = $entity_name.'__'.$field; //matches the format stored in the database

  //list special cases here:
  switch ($table_name) {
    case 'taxonomy_term__description':
      //$query_string = "SELECT tid AS entity_id, description__value AS $column_name FROM taxonomy_term_field_data;";
      $query_string = "SELECT description__value AS $column_name FROM taxonomy_term_field_data WHERE tid = $id;";
      break;
    case 'menu_link_content__link':
      //$query_string = "SELECT id AS entity_id, link__uri AS $column_name FROM menu_link_content_data;";
      $query_string = "SELECT link__uri AS $column_name FROM menu_link_content_data WHERE id = $id;";
      break;
    default:
      //$query_string = "SELECT entity_id, $column_name FROM $table_name;";
      $query_string = "SELECT $column_name FROM $table_name WHERE entity_id = $id;";
  }

  //run the query and retrieve the results
  $r = run_query($query_string)[0]; // retrieved value
  return array('field_value' => $r[$column_name], 'entity_id'=> $id, 'type' => $type, 'entity' => $entity_name, 'entity_field' => $field);
}

function run_query($query_string) {
  //try querying the database with generated query strings for link containing fields and store in the $value_sets array
  try {
    $retrieved = \Drupal::database()->query($query_string)->fetchAll();
    $retrieved = json_decode(json_encode($retrieved), true);
    return $retrieved;

  } catch(\Drupal\Core\Database\DatabaseExceptionWrapper $e) {
    return FALSE;
    \Drupal::Messenger()->addWarning("invalid query: $query_string");
  } catch(\Drupal\Core\Database\IntegrityConstraintViolationException $e) {
    return FALSE;
    \Drupal::Messenger()->addWarning("invalid query: $query_string");
  }
}

function add_alias($item, $entities) {
  $entity_aliases = array_combine(array_keys($entities), array_column($entities, 'alias'));

  $alias = null;
  if ($item['entity_id'] != null) {
    if ($item['entity'] == 'paragraph') {
      $paragraph_parent_data = return_paragraph_parent($item['entity_id'], $entities);
      if (in_array($paragraph_parent_data['parent_type'], array_keys($entities))) {
        $alias = $entity_aliases[$paragraph_parent_data['parent_type']] . $paragraph_parent_data['parent_id'];
      } else {
        $alias = "";
      }
    } else if (array_key_exists($item['entity'], $entity_aliases)) {
      $alias = $entity_aliases[$item['entity']] . $item['entity_id'];
    } else {
      $alias = $item['entity_id'];
    }
  }
  $item['alias'] = $alias;

  return $item;
}

/**
 * Traces the paragraph back to its parent entity by the paragraph entity_id
 * Returns the parent entities type, and id
 * Recursive function may call itself if the paragraph is nested in more paragraphs.
 * @param $entity_id - the entity id of the node
 * @return array|null - an array with two elements: the parent type, and parent id; null if parent is not on the entities list
 */
function return_paragraph_parent($entity_id) {
  $query_string = "SELECT parent_id, parent_type FROM paragraphs_item_field_data WHERE id = $entity_id;";
  $retrieved = run_query($query_string)[0];

  if($retrieved['parent_type'] == "paragraph") {
    return $this.return_paragraph_parent($retrieved['parent_id']);
  } else {
    return ['parent_type' => $retrieved['parent_type'], 'parent_id' => $retrieved['parent_id']];
  }

}

/**
 * Extracts urls from the text
 * @param array $values - string values for each field that was queried in the database.
 * @return array - an array of the urls that were extracted along with the node alias and entity type for reference
 */
function extract_urls_from_text(array $value) {
  if ($value['type'] == 'value') {
    //these are urls that are embedded as hyperlinks in paragraphs or sentences.
    return extract_urls_from_value($value);
  } else {
    return extract_url($value);
  }
}

/**
 * Extracts urls from html text and returns an array
 * @param $value
 * @return array
 */
function extract_urls_from_value($value) {
  $extracted_urls = [];
  $html = $value['field_value'];
  $hrefs = [];
  preg_match_All("#<a\s[^>]*href\s*=\s*[\'\"]??\s*?(?'path'[^\'\"\s]+?)[\'\"\s]{1}[^>]*>(?'name'[^>]*)<#simU", $html, $hrefs, PREG_SET_ORDER);
  //array_push($extracted_urls, array('url' => 'https://google.com', 'alias' => $value['alias'], 'entity' => $value['entity'], 'entity_field' => $value['entity_field'], 'entity_id' => $value['entity_id']));

  foreach ($hrefs as $urls){
    $url = $urls['path'];
    array_push($extracted_urls, array('url' => $url, 'alias' => $value['alias'], 'entity' => $value['entity'], 'entity_field' => $value['entity_field'], 'entity_id' => $value['entity_id']));
  }
  return $extracted_urls;
}

/**
 * Extracts urls from other types
 * @param $value
 * @return array
 */
function extract_url($value) {
  $html = $value['field_value'];

  //list special cases here:
  if (substr($html, 0, 7) == "entity:") {
    $html = "/".substr($html,7);
  }
  //enter more types here...

  return(array(array('url' => $html, 'alias' => $value['alias'], 'entity' => $value['entity'], 'entity_field' => $value['entity_field'], 'entity_id' => $value['entity_id'])));
}

function insert_into_database($table, $insert_object) {
  try {
    \Drupal::database()->insert($table)->fields($insert_object)->execute();
  } catch (\Exception $e) {
    \Drupal::Messenger()->addWarning("could not insert object into database");
  }
}

/**
 * Insert the newly extracted URLs into the URL report
 * @param $extracted_urls - a list of newly extracted urls
 */
function insert_new_links_into_database($extracted_urls) {
  foreach ($extracted_urls as $url) {
    $url['status'] = NULL;
    $url['reason'] = NULL;
    insert_into_database('link_checker_report', $url);
  }
}
