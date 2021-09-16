<?php

namespace Drupal\link_checker\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

class LinkCheckerForm extends FormBase {

  //machine name of entities with corresponding display name and url alias
  //to see all default values, check the link_checker.settings.yml configuration file
  private $entities = [];

  //machine names of field types
  //to see all default values, check the link_checker.settings.yml configuration file
  private $field_types = [];

  //field-map ref
  private $field_map = [];

  /**
   * @inheritDoc
   */
  public function getFormId() {
    return 'link_checker_form';
  }

  /**
   * @inheritDoc
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    //get the configuration settings yml
    $config = \Drupal::config('link_checker.settings')->get('config_defaults');

    //create entities and field types from the configuration settings
    $entities = [];
    $field_types = [];
    if(!empty($config)){
      foreach($config['entity_types_fieldset'] as $key){
        $entities[$key['machine_name']] = ['name' => $key['name'], 'alias' => $key['url']];
      }
      foreach($config['field_types_fieldset'] as $key){
        $field_types[$key['name']] = $key['type'];
      }
    }

    //generate field map to find all the fields for each entity type
    $field_map = $this->field_map = \Drupal::entityManager()->getFieldMap();

    //load default checkbox selections from the configuration settings to array
    $default_checkboxes = \Drupal::config('link_checker.settings')->get('default_checkboxes');

    //if the key doesnt exist in the default checkboxes array yet, then add it.
    foreach($entities as $key => $value) {
      if(!array_key_exists($key, $default_checkboxes)) {
        $default_checkboxes[$key] = array();
      }
    }

    //if key exists in default checkboxes that is not is entities array, then remove it.
    foreach($default_checkboxes as $key => $value) {
      if(!array_key_exists($key, $entities)) {
        unset($default_checkboxes[$key]);
      }
    }

    //FORM PAGE DESCRIPTION
    $form['description'] = [
      '#type' => 'markup',
      '#markup' => $this->t('<p><strong>Please select which fields you would like to run the link report on.</strong></p>'),
    ];

    //FORM ENTITY CHECKBOXES:
    //add checkboxes form-type element to hold each checkbox
    foreach($field_map as $entity_type => $field_definitions) {

      //check if the field map entity type is in the list of entity types to check
      if(in_array($entity_type, array_keys($entities))){

        //create array to hold each checkbox value
        $checkbox_list = [];
        $count = 0;
        foreach($field_definitions as $field_machine_name => $field_definition){
          if(in_array($field_definition['type'], array_keys($field_types))){
            $type_blacklist = ['revision_log', 'revision_log_message', 'behavior_settings'];
            if(!in_array($field_machine_name, $type_blacklist)) {
              $checkbox_list[$field_machine_name] = $field_machine_name;
              $count++;
            }
          }
        }

        //add checkboxes form-type element to hold the checkbox array
        $form[$entity_type] = [
          '#type' => 'checkboxes',
          '#options' => $checkbox_list,
          '#title' => $entities[$entity_type]['name'],
          '#default_value' => $default_checkboxes[$entity_type],
        ];

        //give message if no fields were found matching settings
        if($count == 0){
          $form[$entity_type]['message'] = [
            '#type' => 'markup',
            '#markup' => $this->t('<p>There are no fields to check, edit the field types in your config.</p>'),
          ];
        }

      }
    }

    //FORM SUBMIT BUTTON
    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save Settings'),
    ];

    $this->entities = $entities;
    $this->field_types = $field_types;
    return $form;
  }

  /**
   * @inheritDoc
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    //Same entity types as above, but only need the keys
    $entity_types = array_keys($this->entities);

    //Get the user input of the submitted form
    $form_input = $form_state->getUserInput();

    //Create a clean array that matches the form output based on the check-marked values
    $fields_table = $this->generate_fields_table($form_input, $entity_types);

    //Save the default checkboxes into config for the next time you load the form
    $config = \Drupal::service('config.factory')->getEditable('link_checker.settings');
    $config->set('default_checkboxes', $fields_table)->save();

    //Create link scanner worker to scan for links
    $queue = \Drupal::service('queue')->get('link_scanner');
    $queue->deleteQueue();
    $queue->createItem($fields_table);

    //Set redirect url then redirect
    $url = \Drupal\Core\Url::fromRoute('link_checker.scan');
    $form_state->setRedirectUrl($url);
  }

  /**
   * creates the fields table array based on the form input
   * @param $form_input - the form input on submit (which boxes are check-marked)
   * @param $entity_types - the entity types to be added
   * @return array - the generated fields table
   */
  private function generate_fields_table($form_input, $entity_types) {
    $fields_table = [];
    foreach($form_input as $key => $values) {
      foreach($entity_types as $entity_type) {
        if ($key === $entity_type) {
          if ($form_input[$key] != NULL) {
            $entity_fields = array_keys(array_filter($form_input[$key]));
            if (!empty($entity_fields)) {
              $fields_table[$key] = $entity_fields;
            }
          }
        }
      }
    }
    return $fields_table;
  }
}
