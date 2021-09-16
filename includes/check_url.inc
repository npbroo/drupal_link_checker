<?php

function check_entity_urls($dataset) {
  foreach($dataset as $data) {
    check_entity_url($data);
  }
}

function check_entity_url($data)
{
  //check url
  $url_set = checkURL($data['url']);

  if ($url_set['reason'] != 'OK') {
    //edit database links status and reason
    updateReport($data['id'],$url_set['reason'],$url_set['status']);
  } else {
    //delete the id from the report
    deleteGoodIdFromReport($data['id']);
  }
}


/**
 * Update the link_checker_report at id with the status and reason
 * @param $id - the id of the link to update
 * @param $reason - the reason to add to the id
 * @param $status - the status to add to the id
 */
function updateReport($id, $reason, $status) {
  \Drupal::database()->update('link_checker_report')
    ->fields(
      [
        'reason' => $reason,
        'status' => $status,
      ]
    )
    ->condition('id', $id)
    ->execute();
}

/**
 * @param array $good_ids - the list of checked ids to delete
 */
function deleteGoodIdFromReport($good_id) {
  //clear the good ids from the queue
  \Drupal::database()->delete('link_checker_report')
    ->condition('id', $good_id)
    ->execute();
}

/**
 * Checks the url to see if it is valid
 * @param $url - the url to check
 * @return array - with the alias, url, status code, and status message
 * @throws \GuzzleHttp\Exception\GuzzleException
 */
function checkURL($url) {
  $host = \Drupal::request()->getSchemeAndHttpHost();

  //check and see if the url is an internal link
  //if it is, then concatenate it to the end of the host url for testing
  if($url[0] == '/') {
    $full_url = $host.$url;
  } else {
    $full_url = $url;
  }

  //test the link
  try {
    $response = \Drupal::httpClient()->request('GET', $full_url, ['http_errors' => false]);
    $status_code = (string) $response->getStatusCode();
    $reason = (string) $response->getReasonPhrase();

    //test to see if link is hardcoded
    if (strpos($url, '.pantheonsite.io')) {
      if(substr($url, 0, strlen($host)) !=  $host){
        $reason = 'Hardcoded/ wrong base';
      } else {
        $reason = 'Hardcoded/ right base';
      }
    }

    //return the processed link and its attributes
    return array('status' => $status_code, 'reason' => $reason);

  } catch (\GuzzleHttp\Exception\ConnectException $e) {
    return array('status' => 'ERROR', 'reason' => 'Connect Exception');
  } catch (\GuzzleHttp\Exception\RequestException $e) {
    return array('status' => 'ERROR', 'reason' => 'Request Exception');
  }
}

function check_entity_url_finished($success, $results, $operations) {
  if ($success) {
    $message = t('All URLs Processed.');
    //$message = \Drupal::translation()->formatPlural(count($results), 'One URL processed.', '@count URLs processed.');
  }
  else {
    $message = t('Finished with an error.');
  }

  \Drupal::Messenger()->addMessage($message);

}
