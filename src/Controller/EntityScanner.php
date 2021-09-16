<?php

namespace Drupal\link_checker\Controller;

use Drupal\Core\Access\AccessResultAllowed;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;

class EntityScanner extends ControllerBase
{

  public function scan_entities() {

    $fields_table = \Drupal::config('link_checker.settings')->get('default_checkboxes');
    $config = \Drupal::config('link_checker.settings')->get('config_defaults');

    //Clear the entity_last_processed and link_checker_report tables of entities not in the search settings
    $this->clean_database_table($fields_table, 'link_checker_entity_last_processed' );
    $this->clean_database_table($fields_table, 'link_checker_report' );

    //create a list of all entities matching the search settings
    $searchable_entities = $this->simple_query_database($fields_table);
    //ksm($searchable_entities); //The list of searchable entities

    // if an entity is found that matches the search settings and it is not in the last processed table, then add it
    // this means the entity is new because of different search settings or the entity is new to the site since the last scan
    // (Set its 'changed' value to NULL to indicate that it is a new entity)
    $this->add_new_entities_to_last_processed_table($searchable_entities);

    //compares timestamps of entities and creates a list of entities that are new or changed since last scan
    $new_entities = $this->check_entities_for_updates();
    //ksm($new_entities); //The list of new entities

    //Extract field types and entities from config
    $field_types = $this->get_field_types($config);
    $entities = $this->get_entities($config);
    $field_map = \Drupal::entityManager()->getFieldMap();

    //Cron Approach
    //

    //Batch Approach
    $batch = $this->createExtractionBatch($new_entities, $field_types, $field_map, $entities);
    //ksm($batch);
    if(!empty($batch)) {
      batch_set($batch);
      return batch_process(Url::fromRoute('link_checker.lists'));
    }
      return $this->redirect('link_checker.lists');

  }


  /**
   * Extracts the field types saved in the config file
   * @param $config - the link checker config defaults
   * @return array - field types
   */
  private function get_field_types($config) {
    $field_types = [];
    if(!empty($config)){
      //extract the field types from the config defaults array
      foreach($config['field_types_fieldset'] as $key){
        $field_types[$key['name']] = $key['type'];
      }
    }
    return $field_types;
  }

  /**
   * Extracts the entities saved in the config file
   * @param $config - the link checker config defaults
   * @return array - entities
   */
  private function get_entities($config) {
    $entities = [];
    if(!empty($config)){
      //extract the entities from the config defaults array
      foreach($config['entity_types_fieldset'] as $key){
        $entities[$key['machine_name']] = ['name' => $key['name'], 'alias' => $key['url']];
      }
    }
    return $entities;
  }

  /**
   * Clears all of the entities from the entity_last_processed table not matching the entity types in the settings.
   * @param $config - the default checkboxes configuration table
   * @param $table_name - the name of the table to clean.
   */
  private function clean_database_table($config, $table_name) {
    $valid_ids = [];
    foreach($config as $entity => $fields) {
      foreach($fields as $field) {
        $ids = \Drupal::database()->select($table_name, 'lcp')
          ->fields('lcp', ['id'])
          ->condition('entity', $entity)
          ->condition('entity_field', $field)
          ->execute()
          ->fetchCol(0);
        $valid_ids = array_merge($valid_ids, $ids);
      }
    }
    if(!empty($valid_ids)){
      \Drupal::database()->delete($table_name)
        ->condition('id', $valid_ids, 'NOT IN')
        ->execute();
    }
    else {
      \Drupal::database()->delete($table_name)->execute();
    }
  }

  /**
   * Gets all of the ids for each entity and its entity type corresponding to the field table given
   * @param $fields_table - an array of the entities and entity types to process (default checkboxes)
   * @return array - all of the ids for each entity and entity type
   */
  private function simple_query_database($fields_table) {
    //Use a database query to selectively get all entities and their ids that match the search settings
    //just need entity type and id from all entity type__field types tables

    $searchable_entities = [];
    foreach($fields_table as $table => $columns) {
      foreach($columns as $column) {

        $table_name = $table.'__'.$column; //matches the format stored in the database

        //list special cases here:
        switch ($table_name) {
          case 'taxonomy_term__description':
            $table_name = "taxonomy_term_field_data";
            break;
          case 'menu_link_content__link':
            $table_name = "menu_link_content_data";
            break;
        }
        //run the query and retrieve the results
        $query_string = "SELECT revision_id as entity_id FROM $table_name;";
        $retrieved = $this->run_query($query_string);

        foreach($retrieved as $r) {
          array_push($searchable_entities, array('entity' => $table, 'entity_field' => $column, 'entity_id' => $r['entity_id']));
        }

      }
    }
    return $searchable_entities;
  }

  /**
   * Adds new entities into the last_processed_table (sets their timestamp as null)
   * @param $searchable_entities - a list of all of the ids for each entity/field type combo
   */
  private function add_new_entities_to_last_processed_table($searchable_entities) {
    //if an entity is found that matches the search settings and it is not in the last processed table, then add it
    // (but set its timestamp as null)

    foreach($searchable_entities as $entity) {
      $table = "link_checker_entity_last_processed";
      $e = $entity['entity'];
      $id = $entity['entity_id'];
      $query_string = "SELECT id FROM $table WHERE entity = '$e' AND entity_id = $id;";
      $retrieved = $this->run_query($query_string);

      //if its not in the table, then add it
      if(empty($retrieved)) {
        $insert_object = ['entity' => $e, '$entity_field' => $entity['entity_field'], 'entity_id' => $id, 'changed' => NULL];
        $this->insert_into_database('link_checker_entity_last_processed', $insert_object);
      }
    }
  }

  /**
   * @return array - the entity type, field type, and ids of the entities to pull values from
   */
  private function check_entities_for_updates() {
    //checks the list of nodes to see when they were last updated
    //if they have been updated since the last scan add them to the query

    //query the link_checker_entity_last_processed table and grab an array of entities, entity_fields, and entity_ids
    $query_string = "SELECT entity, entity_field, entity_id, changed FROM link_checker_entity_last_processed";
    $retrieved = $this->run_query($query_string);

    $new_entities = [];
    foreach($retrieved as $entity) {
      $entity_type = $entity['entity'];
      $id = $entity['entity_id'];
      $entity_field = $entity['entity_field'];
      $last_processed_timestamp = $entity['changed'];

      //get the changed timestamp attached to the entity
      $entity_changed_timestamp = $this->return_last_updated_time($entity_type, $id);

      $update_timestamp = FALSE;
      if($last_processed_timestamp != NULL) {
        if($last_processed_timestamp < $entity_changed_timestamp ){
          //if this is true, then the entity has been revised since last checked and a link inside might have changed
          //update the last processed timestamp to reflect the entity's new last changed timestamp
          $update_timestamp = TRUE;

          //delete all occurrences of links from this entity in the database
          \Drupal::database()->delete('link_checker_report')
            ->condition('entity_id', $id)
            ->condition('entity', $entity_type)
            ->execute();
        }
      }else {
        //if its null, then it is a new value on the list
        //in this case update its changed from NULL to reflect the last changed
        $update_timestamp = TRUE;
      }

      if($update_timestamp) {
        \Drupal::database()->update('link_checker_entity_last_processed')
          ->fields([
            'changed' => $entity_changed_timestamp,
          ])
          ->condition('entity', $entity_type)
          ->condition('entity_id', $id)
          ->execute();

        //push the new entities to an array
        array_push($new_entities, array('entity'=>$entity_type, 'entity_field'=>$entity_field, 'entity_id'=>$id));
      }

    }
    return $new_entities;
  }

  private function return_last_updated_time($entity_type, $id) {

    //if the entity type is a paragraph retrieve its parent node and check when it was last updated instead.
    //there should not be any paragraph types that make it to the special cases
    if($entity_type == 'paragraph') {
      $paragraph_parent_data = $this->return_paragraph_parent($id);
      $entity_type = $paragraph_parent_data['parent_type'];
      $id = $paragraph_parent_data['parent_id'];
    }

    //assign default values
    $revision_table_name = $entity_type.'_field_data'; //node, media, block_content, taxonomy_term
    $id_type = 'revision_id'; //block_content, taxonomy_term, paragraph, menu

    //list special cases here:
    //assign query string for where the revision and id type
    switch($entity_type) {
      /*case 'paragraph':
        $revision_table_name = 'paragraphs_item_field_data';
        break;*/
      case 'menu_link_content':
        $revision_table_name = 'menu_link_content_data';
        break;
      case 'node':
      case 'media':
        $id_type = 'vid';
        break;
    }

    $query_string = "SELECT changed FROM $revision_table_name WHERE $id_type = $id;";
    $retrieved = $this->run_query($query_string);

    return $retrieved[0]['changed'];
  }

  private function run_query($query_string) {
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

  private function insert_into_database($table, $insert_object) {
    try {
      \Drupal::database()->insert($table)->fields($insert_object)->execute();
    } catch (\Exception $e) {
      \Drupal::Messenger()->addWarning("could not insert object into database");
    }
  }

  /**
   * Traces the paragraph back to its parent entity by the paragraph entity_id
   * Returns the parent entities type, and id
   * Recursive function may call itself if the paragraph is nested in more paragraphs.
   * @param $entity_id - the entity id of the node
   * @return array|null - an array with two elements: the parent type, and parent id; null if parent is not on the entities list
   */
  private function return_paragraph_parent($entity_id) {
    $query_string = "SELECT parent_id, parent_type FROM paragraphs_item_field_data WHERE id = $entity_id;";
    $retrieved = $this->run_query($query_string)[0];

    if($retrieved['parent_type'] == "paragraph") {
      return $this.return_paragraph_parent($retrieved['parent_id']);
    } else {
      return ['parent_type' => $retrieved['parent_type'], 'parent_id' => $retrieved['parent_id']];
    }

  }

  /**
   * Adds each link to cron to be processed later
   */
  public function createExtractionBatch($entities, $field_types, $field_map, $entity_list)
  {

    if(!empty($entities)) {

      /* UNCOMMENT TO NOT BATCH OPERATIONS
      $operations = [];
      foreach ($entities as $entity){
        $operations[] = ['extract_urls_from_entity', [$entity, $field_types, $field_map, $entity_list]];
      }
      */
      $config_defaults = \Drupal::config('link_checker.settings')->get('config_defaults');
      $batch_size = $config_defaults['batch_fieldset']['extraction_batch_size'];

      //BATCH OPERATIONS
      $operations = [];
      $e = [];
      $c = 0;
      //$batch_size = 500;
      foreach ($entities as $entity) {
        array_push($e, $entity);
        $c++;
        if ($c == $batch_size) {
          $operations[] = ['extract_urls_from_entities', [$e, $field_types, $field_map, $entity_list]];
          $c = 0;
          $e = array();
        }
      }
      if ($c > 0) {
        $operations[] = ['extract_urls_from_entities', [$e, $field_types, $field_map, $entity_list]];
      }

      //END BATCH
      $num_entities = count($entities);
      $batch = array(
        'title' => t("Scanning Entities For URLs... ($num_entities total)"),
        'operations' => $operations,
        //'finished' => 'create_queue',
      );

      return $batch;

      //batch_set($batch);
      //batch_process(Url::fromRoute('link_checker.lists'));
    }
    return array();
  }


  /**
   * Adds each link to cron to be processed later
   */
  /*
  public function createQueue()
  {
    //get queue list from links in database
    $query_string = "SELECT id, url FROM link_checker_report WHERE status IS NULL;";
    $result = \Drupal::database()->query($query_string)->fetchall();
    $queued_links = json_decode(json_encode($result), true);

    //create queue
    $queue = \Drupal::queue('link_processor');
    $queue->deleteQueue();
    $queue->createQueue();

    //add links to queue
    while (!empty($queued_links)) {
      $queue->createItem(array_pop($queued_links));
    }
  }
  */
}
