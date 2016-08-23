<?php

namespace Terminus\Commands;

use Terminus\Session;
use Terminus\Commands\TerminusCommand;
use Terminus\Models\User;
use Terminus\Models\Organization;
use Terminus\Models\OrganizationSiteMembership;
use Terminus\Models\Collections\Sites;
use Terminus\Models\Collections\UserOrganizationMemberships;

/**
 * Show information for your Pantheon organizations
 *
 * @command organizations
 */
class OrganizationsCommand extends TerminusCommand {

  /**
   * Object constructor
   *
   * @param array $options Options to construct the command object
   * @return OrganizationsCommand
   */
  public function __construct(array $options = []) {
    $options['require_login'] = true;
    parent::__construct($options);
    $this->sites = new Sites();
    $this->user  = Session::getUser();
  }

  /**
   * Show a list of your organizations on Pantheon
   *
   * @subcommand list
   */
  public function all($args, $assoc_args) {
    $data          = [];
    $organizations = $this->user->getOrganizations();
    foreach ($organizations as $id => $org) {
      $data[] = [
        'name' => $org->get('profile')->name,
        'id' => $org->get('id'),
      ];
    }

    $this->output()->outputRecordList($data);
  }

  /**
   * List an organization's sites
   *
   * ## OPTIONS
   *
   * <add|remove|list>
   * : subfunction to run
   *
   * [--org=<id|name>]
   * : Organization UUID or name
   *
   * [--tag=<tag>]
   * : Tag name to filter sites list by
   *
   * [--site=<site>]
   * : Site to add to or remove from organization
   *
   * @subcommand sites
   */
  public function sites($args, $assoc_args) {
    $action   = array_shift($args);

    switch ($action) {
      case 'add':
        $this->addSiteToOrganization($assoc_args);
          break;
      case 'remove':
        $this->removeSiteFromOrganization($assoc_args);
          break;
      case 'list':
      default:
        $data = $this->listOrganizationalSites($assoc_args);
        return $data;
          break;
    }
  }

  /**
   * List an organization's team members
   *
   * ## OPTIONS
   *
   * <list|add-member|remove-member|change-role>
   * : Options are list, add-member, remove-member, and change-role.
   *
   * [--org=<id|name>]
   * : Organization UUID or name
   *
   * [--member=<email>]
   * : Email of the member to add. Member will receive an invite
   *
   * [--role=<role>]
   * : Role for the new member to act as. Options are admin, team_member, and
   *   developer.
   *
   * @subcommand team
   */
  public function team($args, $assoc_args) {
    $action = array_pop($args);

    $org_id = $this->input()->orgId(
      array(
        'args'       => $assoc_args,
        'allow_none' => false,
      )
    );
    $orgs = new UserOrganizationMemberships();
    $org  = $orgs->get($org_id);
    if (is_null($org)) {
      $message  = 'The organization {org} is either invalid or you haven\'t';
      $message .= ' permission sufficient to access its data.';
      $this->failure(
        $message,
        array('org' => $assoc_args['org'])
      );
    }
    $org_info     = $org->get('organization');
    $org_model    = new Organization($org_info);
    $role_choices = ['unprivileged', 'admin'];

    switch ($action) {
      case 'add-member':
        $email = $this->input()->string(
          [
            'args'    => $assoc_args,
            'key'     => 'member',
            'message' => 'What is the email address of the user to be added?',
          ]
        );
        $can_change_management = $org_model->getFeature('change_management');
        $role                  = $this->input()->orgRole(
          [
            'args'                  => $assoc_args,
            'can_change_management' => $can_change_management,
            'return_value'          => true,
            'key'                   => 'role',
            'message'               => 'Select a role for your new member.',
          ]
        );
        $workflow = $org->user_memberships->addMember($email, $role);
        $workflow->wait();
        $this->workflowOutput($workflow);
          break;
      case 'remove-member':
        $member = $this->input()->orgMember(
          [
            'args'            => $assoc_args,
            'autoselect_solo' => false,
            'can_pick_self'   => false,
            'message'         => 'Please select a member to remove',
            'org'             => $org,
          ]
        );
        $workflow = $member->removeMember();
        $workflow->wait();
        $this->workflowOutput($workflow);
          break;
      case 'change-role':
        $member   = $this->input()->orgMember(
          [
            'args'            => $assoc_args,
            'autoselect_solo' => false,
            'message'         => 'Please select a member to update',
            'org'             => $org,
          ]
        );
        if ($org_model->getFeature('change_management')) {
          $role_choices[] = 'team_member';
          $role_choices[] = 'developer';
        }
        $can_change_management = $org_model->getFeature('change_management');
        $role     = $this->input()->orgRole(
          [
            'args'                  => $assoc_args,
            'can_change_management' => $can_change_management,
            'return_value'          => true,
            'key'                   => 'role',
            'message'               => 'Select a role for this member.',
          ]
        );
        $workflow = $member->setRole($role);
        $this->workflowOutput($workflow);
          break;
      case 'list':
      default:
        $memberships = $org->user_memberships->all();
        $data        = [];
        foreach ($memberships as $membership) {
          $member = $membership->get('user');

          $first_name = $last_name = null;
          if (isset($member->profile->firstname)) {
            $first_name = $member->profile->firstname;
          }
          if (isset($member->profile->lastname)) {
            $last_name = $member->profile->lastname;
          }

          $data[$member->id] = [
            'first' => $first_name,
            'last'  => $last_name,
            'email' => $member->email,
            'role'  => $membership->get('role'),
            'uuid'  => $member->id,
          ];
        }
        $this->output()->outputRecordList($data);
          return $data;
    }
  }

  /**
   * Adds an existing site to an organization
   *
   * @param array $assoc_args Arguments from the command line
   * @return void
   */
  private function addSiteToOrganization($assoc_args) {
    $org = $this->user->org_memberships->getOrganization(
      $this->input()->orgId(['args' => $assoc_args, 'allow_none' => false,])
    );
    $choices = array_combine(
      array_map(
        function ($membership) {
          return $membership->site->id;
        },
        $this->user->site_memberships->all()
      ),
      array_map(
        function ($membership) {
          $site_name = $membership->site->get('name');
          return $site_name;
        },
        $this->user->site_memberships->all()
      )
    );
    $site = $this->input()->siteName(
      [
        'choices'       => array_combine(
          array_map(
            function ($membership) {
              return $membership->site->id;
            },
            $this->user->site_memberships->all()
          ),
          array_map(
            function ($membership) {
              $site_name = $membership->site->get('name');
              return $site_name;
            },
            $this->user->site_memberships->all()
          )
        ),
        'message'       => 'Choose site',
        'return_object' => true,
      ]
    );
    $this->input()->confirm(
      [
        'message' => 'Are you sure you want to add %s to %s ?',
        'context' => [$site->get('name'), $org->get('profile')->name,],
      ]
    );
    $workflow = $org->site_memberships->create($site);
    $workflow->wait();
    $this->workflowOutput($workflow);
  }

  /**
   * Removes a site from an organization
   *
   * @param array $assoc_args Arguments from the command line
   * @return void
   */
  private function removeSiteFromOrganization($assoc_args) {
    $org = $this->user->org_memberships->getOrganization(
      $this->input()->orgId(['args' => $assoc_args, 'allow_none' => false,])
    );
    $site_memberships = $org->site_memberships->all();
    $choices = array_combine(
      array_map(
        function ($membership) {
          return $membership->site->id;
        },
        $site_memberships
      ),
      array_map(
        function ($membership) {
          $site_name = $membership->site->get('name');
          return $site_name;
        },
        $site_memberships
      )
    );
    $site = $this->input()->siteName(
      [
        'choices'       => array_combine(
          array_map(
            function ($membership) {
              return $membership->site->id;
            },
            $site_memberships
          ),
          array_map(
            function ($membership) {
              $site_name = $membership->site->get('name');
              return $site_name;
            },
            $site_memberships
          )
        ),
        'message'       => 'Choose site',
        'return_object' => true,
      ]
    );
    $this->input()->confirm(
      [
        'message' => 'Are you sure you want to remove %s from %s ?',
        'context' => [$site->get('name'), $org->get('profile')->name,],
      ]
    );
    $workflow = $org->site_memberships->delete($site);
    $workflow->wait();
    $this->workflowOutput($workflow);
  }

  /**
   * Lists the sites belonging to an organization
   *
   * @param array $assoc_args Args from the command line
   * @return array $data Data to display about sites in the organization
   */
  private function listOrganizationalSites($assoc_args) {
    $org = $this->user->org_memberships->getOrganization(
      $this->input()->orgId(['args' => $assoc_args, 'allow_none' => false,])
    );

    $tag = $this->input()->optional(
      ['key' => 'tag', 'choices' => $assoc_args,]
    );
    $site_memberships = $org->site_memberships->all();
    if (!is_null($tag)) {
      $site_memberships = array_filter(
        function ($membership) {
          $has_tag = in_array($tag, $membership->get('tags'));
          return $has_tag;
        },
        $site_memberships
      );
    }

    $data = array_map(
      function ($membership) {
        $site = $membership->site;
        $site_info = [
          'name'          => $site->get('name'),
          'id'            => $site->id,
          'service_level' => $site->get('service_level'),
          'framework'     => $site->get('framework'),
          'created'       => date(TERMINUS_DATE_FORMAT, $site->get('created')),
          'tags'          => $membership->get('tags'),
        ];
        if ((boolean)$site->get('frozen')) {
          $site_info['frozen'] = true;
        }
        return $site_info;
      },
      $site_memberships
    );

    if (empty($data)) {
      $message = 'No sites match your ';
      if (empty($assoc_args)
        || ((count($assoc_args) == 1) && (isset($assoc_args['org'])))
      ) {
        $message .= 'criterion.';
      } else {
        $message .= 'criteria.';
      }
      $this->log()->info($message);
    }
    $this->output()->outputRecordList($data);
    return $data;
  }

}

