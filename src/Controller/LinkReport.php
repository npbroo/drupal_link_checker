<?php

namespace Drupal\link_checker\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Response;
use Drupal\Core\Url;

class LinkReport extends ControllerBase {

  /**
   * Loads the saved config variables and passes them to the twig theme
   */
  public function displayLinkReport() {
      //Load the stats variables to pass to the Twig theme
      $stats = \Drupal::config('link_checker.settings')->get('stats');

      //retrieve the url report from the database
      $url_report = $this->retrieve_url_report();
      $url_report_queue = $this->retrieve_url_report_queue();

      //Call the twig theme
      return [
          '#theme' => 'link_report',
          '#url_report' => $url_report,
          '#url_report_queue' => $url_report_queue,
          '#stats' => $stats,
        ];
  }

  /**
   * Create connection to the database and load the url report
   * @return array - the url report
   */
    private function retrieve_url_report() {
      //Create connection to the database and load urls that have been processed.
      $query_string = "SELECT id, entity, alias, url, status, reason FROM link_checker_report WHERE status IS NOT NULL;";
      $result = \Drupal::database()->query($query_string)->fetchall();
      return json_decode(json_encode($result), true); //Convert an array/stdClass -> array and return
    }

  /**
   * Retrieves the list of links waiting to be checked
   * @return array - the links in queue
   */
  private function retrieve_url_report_queue() {
    //Create connection to the database and load urls that have not been processed.
    $query_string = "SELECT url FROM link_checker_report WHERE status IS NULL;";
    $result = \Drupal::database()->query($query_string)->fetchall();
    return json_decode(json_encode($result), true); //Convert an array/stdClass -> array and return
  }

  /**
   * Runs Cron and redirects back to the link display page.
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   */
  public function runCron() {
    \Drupal::service('cron')->run();
    return $this->redirect('link_checker.lists');
  }

  /**
   * Creates a batch to be run that checks the link
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   */
  public function createLinkCheckBatch()
  {
    //get queue list from links in database
    $query_string = "SELECT id, url FROM link_checker_report WHERE status IS NULL;";
    $result = \Drupal::database()->query($query_string)->fetchall();
    $queued_links = json_decode(json_encode($result), true);
    $num_links = count($queued_links);

    if (!empty($queued_links)) {

      /* UNCOMMENT TO NOT BATCH OPERATIONS
      $operations = [];
      while (!empty($queued_links)) {
        $operations[] = ['check_entity_url', [array_pop($queued_links)]];
      }*/

      $config_defaults = \Drupal::config('link_checker.settings')->get('config_defaults');
      $batch_size = $config_defaults['batch_fieldset']['link_check_batch_size'];

      //BATCH OPERATIONS
      $operations = [];
      $e = [];
      $c = 0;
      //$batch_size = 20;
      while (!empty($queued_links)) {
        array_push($e, array_pop($queued_links));
        $c++;
        if ($c == $batch_size) {
          $operations[] = ['check_entity_urls', [$e]];
          $c = 0;
          $e = array();
        }
      }
      if ($c > 0) {
        $operations[] = ['check_entity_urls', [$e]];
      }
      //END BATCH

      $batch = array(
        'title' => t("Processing the URL Batch... ($num_links total)"),
        'operations' => $operations,
        'finished' => 'check_entity_url_finished',
      );

      //ksm($batch);
      batch_set($batch);
      return batch_process(Url::fromRoute('link_checker.lists'));
    }
    return $this->redirect('link_checker.lists');
  }

  /**
   * Downloads a csv output in the browser
   * @return Response
   */
    public function downloadCsv() {
      //add necessary headers for browsers
      $response = new Response();
      header('Content-Type: text/csv; utf-8');
      header('Content-Disposition: attachment; filename="url_report.csv"');

      //instead of writing down to a file we write to the output stream
      $fh = fopen('php://output', 'w');

      //form header
      fputcsv($fh, array('Entity', 'Alias', 'Url', 'Status', 'Reason'));

      //retrieve the url report from the database
      $url_report = $this->retrieve_url_report();

      //write data in the CSV format
      foreach ($url_report as $url) {
        fputcsv($fh, array( t($url['entity']), t($url['alias']), t($url['url']), t($url['status']), t($url['reason'])));
      }

      //close the stream
      fclose($fh);

      return $response;
    }
}
