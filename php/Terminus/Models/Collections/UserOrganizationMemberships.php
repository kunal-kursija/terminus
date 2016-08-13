<?php

namespace Terminus\Models\Collections;

class UserOrganizationMemberships extends TerminusCollection {
  /**
   * @var User
   */
  public $user;
  /**
   * @var boolean
   */
  protected $paged = true;

  /**
   * Object constructor
   *
   * @param array $options Options to set as $this->key
   */
  public function __construct($options = []) {
    parent::__construct($options);
    $this->user = $options['user'];
    $this->url  = "users/{$this->user->id}/memberships/organizations";
  }

  /**
   * Adds a model to this collection
   *
   * @param object $model_data  Data to feed into attributes of new model
   * @param array  $arg_options Data to make properties of the new model
   * @return void
   */
  public function add($model_data = [], array $arg_options = []) {
    $default_options = [
      'id'         => $model_data->id,
      'collection' => $this,
    ];
    $options         = array_merge($default_options, $arg_options);
    parent::add($model_data, $options);
  }

  /**
   * Retrieves the model of the given ID
   *
   * @param string $id ID or name of desired organization
   * @return UserOrganizationMembership $model
   */
  public function get($id) {
    $model = null;
    if (isset($this->models[$id])) {
      $model = $this->models[$id];
    } else {
      foreach ($this->models as $model_candidate) {
        if ((isset($model_candidate->profile)
            && ($id == $model_candidate->profile->name))
          || (isset($model_candidate->get('organization')->profile)
            && $model_candidate->get('organization')->profile->name == $id)
        ) {
          $model = $model_candidate;
          break;
        }
      }
    }
    return $model;
  }

  /**
   * Names the model-owner of this collection
   *
   * @return string
   */
  protected function getOwnerName() {
    $owner_name = 'user';
    return $owner_name;
  }

}
