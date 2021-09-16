<?php

namespace Drupal\link_checker\Form;

use Cassandra\Exception\TruncateException;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use function Couchbase\defaultEncoder;

class ConfigurationForm extends FormBase
{

  /**
   * @inheritDoc
   */
  public function getFormId()
  {
    return 'configuration_form';
  }

  /**
   * Builds the form.
   * @inheritDoc
   */
  public function buildForm(array $form, FormStateInterface $form_state)
  {

    //load defaults from the configuration file
    $config_defaults = \Drupal::config('link_checker.settings')->get('config_defaults');

    $default_entries[0] = $config_defaults['entity_types_fieldset'];
    $default_entries[1] = $config_defaults['field_types_fieldset'];

    $initialized = $form_state->get('initialized');

    $entries = $default_entries;
    if (empty($initialized) || $initialized == FALSE) {
      //not initialized
      $form_state->set('initialized', TRUE);
    } else {
      //already initialized
      foreach($default_entries as $key => $entry) {
        $entries[$key] = [];
      }
    }

    // State that the form needs to allow for a hierarchy (ie, multiple
    // names with our names key).
    $form['#tree'] = TRUE;

    //declare variable forms below (these are the forms that can have fields added or removed)
    //each form requires a name and a form_state_variable
    $variable_forms = [
      [
        'title' => 'Entity Types',
        'btn_text' => 'entity type',
        'form_parent_name' => 'entity_types_fieldset', // same name as the config file
        'form_state_var' => 'entities_tracker',
        'defaults' => $entries[0], // default entries
        'structure' => [
          '#type' => 'fieldset',
          'name' => [
            '#type' => 'textfield',
            '#title' => 'Name',
            '#description' => $this->t('The name of the entity that will show up in the settings form.'),
          ],
          'machine_name' => [
            '#type' => 'textfield',
            '#title' => 'Machine Name',
            '#description' => $this->t('The machine name for this entity. It must only contain lowercase letters, numbers, and underscores.'),
          ],
          'url' => [
            '#type' => 'textfield',
            '#title' => 'Entity Url',
            '#description' => $this->t('The url that entity id are appended to which correlates to this entity type.'),
            '#default_value' => '/'
          ],
        ],
      ],
      [
        'title' => 'Field Types',
        'btn_text' => 'field type', //must be different
        'form_parent_name' => 'field_types_fieldset',
        'form_state_var' => 'fields_tracker',
        'defaults' => $entries[1],
        'structure' => [
          '#type' => 'fieldset',
          'name' => [
            '#type' => 'textfield',
            '#title' => 'Machine Name',
            '#description' => $this->t('The name of the field type you want to include.'),
          ],
          'type' => [
            '#type' => 'textfield',
            '#title' => 'Type',
            '#description' => $this->t('The type that it corresponds to.'),
          ],
        ],
      ],
    ];

    //add a page description
    $form['description'] = [
      '#type' => 'markup',
      '#markup' => $this->t('<p><strong>The configuration settings for the link checker are listed below.</strong></p>'),
    ];

    $form['batch_fieldset'] = [
      '#type' => 'details',
      '#title' => $this->t('Batch Sizes'),
      'extraction_batch_size' => [
        '#type' => 'number',
        '#title' => $this->t('<p>How many entities per batch should the scanned for links? <small>(default=500)</small></p>'),
        '#default_value' => $config_defaults['batch_fieldset']['extraction_batch_size'],
        '#min' => 1,
        '#max' => 10000,
      ],
      'link_check_batch_size' => [
        '#type' => 'number',
        '#title' => $this->t('<p>How many links per batch should be tested with an http request? <small>(default=20)</small></p>'),
        '#default_value' => $config_defaults['batch_fieldset']['link_check_batch_size'],
        '#min' => 1,
        '#max' => 1000,
      ],
    ];

    foreach ($variable_forms as $variable_form) {
      $tracker = $this->_initialize($variable_form, $form_state);

      $name = $variable_form['form_parent_name'];
      $form_state_var = $variable_form['form_state_var'];

      // Container for our repeating fields.
      $form[$name] = [
        '#type' => 'details',
        '#title' => $this->t($variable_form['title']),
        '#prefix' => '<div id="name_element_wrapper">',
        '#suffix' => '</div>'
      ];

      // Add our variable form fields.
      foreach ($tracker as $key => $values) {

        $form[$name][$key] = $variable_form['structure'];

        if ($values != 'new') {
          foreach ($values as $k => $value) {
            $form[$name][$key][$k]['#default_value'] = $value;
          }
        }

        $form[$name][$key]['remove'] = [
          '#name' => 'remove_' . $key,
          '#type' => 'submit',
          '#value' => $this->t('Remove '.$variable_form['btn_text']),
          '#tag' =>
            [
              'form_parent' => $name,
              'index' => $key,
              'form_state_var' => $form_state_var,
              'action' => 'remove'
            ],
          '#submit' => array('::_remove_field'),
          '#ajax' => [
            'callback' => '::_ajax',
            'event' => 'change',
            'wrapper' => 'name_element_wrapper',
          ]
        ];
      }

      // Button to add more names.
      $form[$name]['add'] = [
        '#type' => 'submit',
        '#value' => $this->t('Add '.$variable_form['btn_text']),
        '#tag' =>
          [
            'form_parent' => $name,
            'index' => -1,
            'form_state_var' => $form_state_var,
            'action' => 'add',
          ],
        '#submit' => array('::_add_field'),
        '#ajax' => [
          'callback' => '::_ajax',
          'event' => 'change',
          'wrapper' => 'name_element_wrapper',
        ]
      ];

    }

    $form_state->setCached(FALSE);

    // Submit button.
    $form['submit'] = [
      '#type' => 'submit',
      '#variable_forms' => $variable_forms,
      '#value' => $this->t('Save'),
    ];

    // Reset button.
    $form['reset'] = [
      '#type' => 'submit',
      '#submit' => array('::_reset_form'),
      '#value' => $this->t('Reset Changes'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
  }

  /**
   * Submits the form
   * @inheritDoc
   */
  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    //ksm("saving form");
    //get form input
    $form_input = $form_state->getUserInput();
    $variable_forms = $form_state->getTriggeringElement()['#variable_forms'];
    $config_defaults = [];
    $config_defaults['batch_fieldset']['extraction_batch_size'] = $form_input['batch_fieldset']['extraction_batch_size'];
    $config_defaults['batch_fieldset']['link_check_batch_size'] = $form_input['batch_fieldset']['link_check_batch_size'];

    foreach( $variable_forms as $variable_form) {
      $form_parent_name = $variable_form['form_parent_name'];

      $count = 1;
      foreach ($form_input[$form_parent_name] as $entity) {
        $config_defaults[$form_parent_name][$count] = $entity;
        $count++;
      }
    }

    //save the new config defaults
    $config = \Drupal::service('config.factory')->getEditable('link_checker.settings');
    $config->set('config_defaults', $config_defaults)->save();

    //redirect to the settings page
    $url = \Drupal\Core\Url::fromRoute('link_checker.settings');
    $form_state->setRedirectUrl($url);
  }

  public function _reset_form(array &$form, FormStateInterface $form_state) {
    //ksm("resetting form");
    $form_state->set('initialized', FALSE);
    $form_state->setRebuild();
  }

  /**
   * Initialize Forms
   */
  public function _initialize(array $variable_form, FormStateInterface $form_state) {
      $form_state_var = $variable_form['form_state_var'];

      //check for default form entries
      if( ! empty($variable_form['defaults'])) {
        //ksm("not initialized; set entries to default");
        $form_state->set($form_state_var, $variable_form['defaults']);
        return $variable_form['defaults'];
      } else if( ! empty($form_state->get($form_state_var)) ) {
        //ksm("already initialized; work off of previous entries");
        return $form_state->get($form_state_var);
      } else {
        //ksm("not initialized; default entries are not provided");
        $fields_tracker = [];
        $struct = $variable_form['structure'];
        foreach ($struct as $key => $value) {
          if (substr_compare($key, '#', 0, 1) !== 0) { //make sure the key does not start with '#'
            if (array_key_exists('#default_value', $struct[$key])) {
              $fields_tracker[0][$key] = $struct[$key]['#default_value'];
            } else {
              $fields_tracker[0][$key] = '';
            }
          }
        }
        $form_state->set($form_state_var, $fields_tracker);
        return $fields_tracker;
      }
  }

  /**
   * Handle adding new.
   */
  public function _add_field(array &$form, FormStateInterface $form_state) {
    $tag = $form_state->getTriggeringElement()['#tag'];
    $form_state_var = $tag['form_state_var'];

    // Add 1 to the fields tracker
    $fields_tracker = $form_state->get($form_state_var);
    array_push($fields_tracker, 'new');
    $form_state->set($form_state_var, $fields_tracker);

    // Rebuild the form
    $form_state->setRebuild();
  }

  /*
   * Handle removing field.
   */
  public function _remove_field(array &$form, FormStateInterface $form_state) {
    $tag = $form_state->getTriggeringElement()['#tag'];
    $index = $tag['index'];
    $form_state_var = $tag['form_state_var'];

    // Remove the Field at index
    $fields_tracker = $form_state->get($form_state_var);
    unset($fields_tracker[$index]);
    $form_state->set($form_state_var, $fields_tracker);

    // Rebuild the form.
    $form_state->setRebuild();
  }

  public function _ajax(array &$form, FormStateInterface $form_state) {
    $tag = $form_state->getTriggeringElement()['#tag'];
    $form_parent = $tag['form_parent'];
    $action = $tag['action'];

    // Unset the field from the array
    if ($action != 'add') {
      $index = $tag['index'];
      unset($form[$form_parent][$index]);
    }
    return $form[$form_parent];
  }
}


