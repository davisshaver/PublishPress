<?php
/**
 * @package PublishPress
 * @author  PublishPress
 *
 * Copyright (c) 2018 PublishPress
 *
 * ------------------------------------------------------------------------------
 * Based on Edit Flow
 * Author: Daniel Bachhuber, Scott Bressler, Mohammad Jangda, Automattic, and
 * others
 * Copyright (c) 2009-2016 Mohammad Jangda, Daniel Bachhuber, et al.
 * ------------------------------------------------------------------------------
 *
 * This file is part of PublishPress
 *
 * PublishPress is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * PublishPress is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with PublishPress.  If not, see <http://www.gnu.org/licenses/>.
 */

use PublishPress\Core\Modules\AbstractModule;
use PublishPress\Core\Modules\ModuleInterface;

if (!class_exists('PP_Roles')) {

    if (!class_exists('PP_Roles_List_Table')) {
        require_once __DIR__ . '/lib/list_table.php';
    }

    /**
     * Class PP_Roles.
     *
     * @todo Rename this class for PSR-2 compliance.
     */
    class PP_Roles extends AbstractModule implements ModuleInterface
    {
        const SETTINGS_SLUG = 'pp-roles-settings';

        const VALUE_YES = 'yes';

        const VALUE_NO = 'no';

        public $module_name = 'roles';

        public $module;

        public $cap_manage_roles = 'pp_manage_roles';

        /**
         * Construct the PP_Roles class
         */
        public function __construct()
        {
            $this->twigPath = __DIR__ . '/twig';

            $this->module_url = $this->get_module_url(__FILE__);

            // Register the module with PublishPress
            $args = [
                'title'                => __('Roles', 'publishpress'),
                'short_description'    => __('Roles allows you to create custom roles.', 'publishpress'),
                'extended_description' => __('Roles allows you to create custom roles and allow users be on more than one role.',
                    'publishpress'),
                'module_url'           => $this->module_url,
                'icon_class'           => 'dashicons dashicons-feedback',
                'slug'                 => 'roles',
                'default_options'      => [
                    'enabled' => 'on',
                ],
                'messages'             => [
                    'role-added'   => __("Role created. Feel free to add users to the role.", 'publishpress'),
                    'role-updated' => __("Role updated.", 'publishpress'),
                    'role-missing' => __("Role doesn't exist.", 'publishpress'),
                    'role-deleted' => __("Role deleted.", 'publishpress'),
                ],
                'configure_page_cb'    => 'print_configure_view',
                'options_page'         => true,
            ];

            $this->module = PublishPress()->register_module($this->module_name, $args);

            parent::__construct();

            $this->configureTwig();
        }

        protected function configureTwig()
        {
            $function = new Twig_SimpleFunction('settings_fields', function () {
                return settings_fields($this->module->options_group_name);
            });
            $this->twig->addFunction($function);

            $function = new Twig_SimpleFunction('nonce_field', function ($context) {
                return wp_nonce_field($context);
            });
            $this->twig->addFunction($function);

            $function = new Twig_SimpleFunction('submit_button', function () {
                return submit_button();
            });
            $this->twig->addFunction($function);

            $function = new Twig_SimpleFunction('do_settings_sections', function ($section) {
                return do_settings_sections($section);
            });
            $this->twig->addFunction($function);

            $function = new Twig_SimpleFunction('display_role_list_table', function () {
                $wp_list_table = new PP_Roles_List_Table($this->twig);
                $wp_list_table->prepare_items();

                ob_start();
                $wp_list_table->display();
                $output = ob_get_clean();

                return $output;
            });
            $this->twig->addFunction($function);
        }

        /**
         * Initialize the module. Conditionally loads if the module is enabled
         */
        public function init()
        {
            add_action('admin_init', [$this, 'register_settings']);

            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                // Handle any adding, editing or saving
                add_action('admin_init', [$this, 'handle_add_role']);
                add_action('admin_init', [$this, 'handle_edit_role']);
            }

            if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                add_action('admin_init', [$this, 'handle_delete_role']);
            }

            $this->cap_manage_roles = apply_filters('pp_cap_manage_roles', $this->cap_manage_roles);

            add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        }

        /**
         * Install
         *
         * @since 0.7
         */
        public function install()
        {
            // Maybe we just migrated from Edit flow, so we need to convert the User Groups.
            if ($this->convertLegacyUserGroupsToRoles()) {
                $this->cleanupUserGroups();
            }
        }

        /**
         * Upgrade our data in case we need to
         *
         * @since 0.7
         */
        public function upgrade($previous_version)
        {
            if (version_compare($previous_version, '1.10.0', '<=')) {
                $this->addCapabilitiesToAdmin();

                if ($this->convertLegacyUserGroupsToRoles()) {
                    $this->cleanupUserGroups();
                }
            }
        }

        /**
         * Add the capabilities to the admin for managing the roles.
         */
        protected function addCapabilitiesToAdmin()
        {
            // Give permissions for the Admin to edit roles
            $admin_role = get_role('administrator');
            $admin_role->add_cap($this->cap_manage_roles);
        }

        /**
         * Convert the user groups to roles.
         *
         * @return boolean
         */
        protected function convertLegacyUserGroupsToRoles()
        {
            // Try to get the user groups.
            $userGroups = $this->getUserGroups();

            if (!empty($userGroups)) {
                foreach ($userGroups as $userGroup) {
                    $this->convertUserGroupToRole($userGroup);
                }
            }

            return true;
        }

        /**
         * Convert the given user group in to a role. Capabilities should be ported by
         * the Permissions add-on.
         *
         * @param \WP_Term $userGroup
         */
        protected function convertUserGroupToRole($userGroup)
        {
            if (!($userGroup instanceof \WP_Term)) {
                return;
            }

            // Check if the role doesn't exist.
            $role = $this->getRole($userGroup->slug);

            if (empty($role)) {
                // The role doesn't exist. Let's add it.
                $role = $this->addRole($userGroup->slug, $userGroup->name);

                // Check if we need to add users from the user group to the role.
                if (!empty($userGroup->user_ids)) {
                    foreach ($userGroup->user_ids as $userId) {
                        $user = $this->getUserById($userId);

                        if (!empty($user)) {
                            $user->add_role($role->name);
                        }
                    }
                }
            }
        }

        /**
         * Get the role.
         *
         * @param string $name
         *
         * @return WP_Role;
         */
        protected function getRole($name)
        {
            return get_role($name);
        }

        /**
         * Add a role.
         *
         * @param string $name
         * @param string $displayName
         * @param array  $capabilities
         *
         * @return \WP_Role
         */
        protected function addRole($name, $displayName, $capabilities = [])
        {
            return add_role($name, $displayName, $capabilities);
        }

        /**
         * Get a user by id.
         *
         * @param int $id
         *
         * @return \WP_User
         */
        protected function getUserById($id)
        {
            return get_user_by('ID', $id);
        }

        /**
         * Remove the given user group.
         *
         * @param int $userGroupId
         */
        protected function removeUserGroup($userGroupId)
        {
            wp_delete_term($userGroupId, 'pp_usergroup');
        }

        /**
         * Get legacy User Groups.
         *
         * @return array
         */
        protected function getUserGroups()
        {
            $userGroupTerms = get_terms([
                'taxonomy'   => 'pp_usergroup',
                'hide_empty' => false,
            ]);

            if (empty($userGroupTerms)) {
                return false;
            }

            $userGroups = [];
            foreach ($userGroupTerms as $userGroupTerm) {
                $userGroup = get_term_by('id', $userGroupTerm->term_id, 'pp_usergroup');

                if (!$userGroup || is_wp_error($userGroup)) {
                    return $userGroup;
                }

                // We're using an encoded description field to store extra values
                // Declare $user_ids ahead of time just in case it's empty
                $userGroup->user_ids = [];
                $decodedDescription  = maybe_unserialize(base64_decode($userGroup->description));

                if (is_array($decodedDescription)) {
                    foreach ($decodedDescription as $key => $value) {
                        $userGroup->$key = $value;
                    }
                }

                $userGroups[] = $userGroup;
            }

            return $userGroups;
        }

        /**
         * Cleanup user groups.
         */
        protected function cleanupUserGroups()
        {
            // Try to get thea user groups.
            $userGroups = $this->getUserGroups();

            if (!empty($userGroups)) {
                foreach ($userGroups as $userGroup) {
                    $this->removeUserGroup($userGroup->term_id);
                }
            }

            return false;
        }

        /**
         * Enqueue necessary admin styles, but only on the proper pages
         */
        public function enqueue_admin_scripts()
        {
            if (isset($_GET['page']) && $_GET['page'] === 'pp-modules-settings' && isset($_GET['module']) && $_GET['module'] === 'pp-roles-settings') {
                wp_enqueue_script('publishpress-chosen-js', $this->module_url . 'assets/libs/chosen/chosen.jquery.js',
                    ['jquery'], PUBLISHPRESS_VERSION);
                wp_enqueue_script('publishpress-roles-js', $this->module_url . 'assets/js/admin.js',
                    ['jquery', 'publishpress-chosen-js'], PUBLISHPRESS_VERSION);

                wp_enqueue_style('publishpress-chosen-css', $this->module_url . 'assets/libs/chosen/chosen.css', false,
                    PUBLISHPRESS_VERSION);
                wp_enqueue_style('publishpress-roles-css', $this->module_url . 'assets/css/admin.css',
                    ['publishpress-chosen-css'], PUBLISHPRESS_VERSION);
            }
        }

        /**
         * Generate a link to one of the routes actions
         *
         * @since 0.7
         *
         * @param string $action Action we want the user to take
         * @param array  $args   Any query args to add to the URL
         * @return string $link Direct link to delete a route
         */
        public function getLink($args = [])
        {
            if (!isset($args['action'])) {
                $args['action'] = '';
            }
            if (!isset($args['page'])) {
                $args['page'] = PP_Modules_Settings::SETTINGS_SLUG;
            }
            if (!isset($args['module'])) {
                $args['module'] = self::SETTINGS_SLUG;
            }

            // Add other things we may need depending on the action
            switch ($args['action']) {
                case 'delete-role':
                    $args['nonce'] = wp_create_nonce('manage-role');
                    break;
                default:
                    break;
            }

            return add_query_arg($args, get_admin_url(null, 'admin.php'));
        }

        /**
         * Print the content of the configure tab.
         *
         * @throws Exception
         */
        public function print_configure_view()
        {
            $action = isset($_GET['action']) && !empty($_GET['action']) ? $_GET['action'] : 'add-role';

            $role = (object)[
                'name'         => isset($_POST['name']) ? $_POST['name'] : '',
                'display_name' => isset($_POST['display_name']) ? $_POST['display_name'] : '',
            ];

            $link_args = [];

            if (isset($_GET['role-id']) && $action == 'edit-role') {
                $role_id = preg_replace('[a-z\-]', '', $_GET['role-id']);

                if (!empty($role_id)) {
                    $role_obj = get_role($role_id);

                    if (!empty($role_obj)) {
                        $editable_roles = get_editable_roles();

                        $role->name = $role_obj->name;

                        // Look for the display name of the role
                        foreach ($editable_roles as $editable_role => $editable_role_data) {
                            if ($editable_role === $role->name) {
                                $role->display_name = $editable_role_data['name'];
                            }
                        }
                    }

                    $link_args = [
                        'role-id' => $role->name,
                    ];
                }
            }

            // Get a list of users to display in the select field.
            $users = get_users();

            // Get selected users, if any role is being edited.
            $role_users = [];

            if (!empty($role->name)) {
                $users_in_the_role = get_users(
                    [
                        'role' => $role->name,
                    ]
                );

                if (!empty($users_in_the_role)) {
                    foreach ($users_in_the_role as $user) {
                        $role_users[] = $user->ID;
                    }
                }
            }

            echo $this->twig->render(
                'settings-tab-roles.twig.html',
                [
                    'form_action'        => $this->getLink($link_args),
                    'action'             => $action,
                    'options_group_name' => $this->module->options_group_name,
                    'module_name'        => $this->module->slug,
                    'labels'             => [
                        'add_new'           => __('Add New Role', 'publishpress'),
                        'edit'              => __('Edit Role', 'publishpress'),
                        'name'              => __('Name (ID)', 'publishpress'),
                        'name_description'  => __('The name used to identify the role. Only use latin chars and "-".',
                            'publishpress'),
                        'display_name'      => __("Display name", 'publishpress'),
                        'users'             => __("Users", 'publishpress'),
                        'users_description' => __("Add users that belongs to this role.", 'publishpress'),
                    ],
                    'role'               => $role,
                    'users'              => $users,
                    'role_users'         => $role_users,
                    'nonce'              => wp_nonce_field('manage-role'),
                    'errors'             => isset($_REQUEST['form-errors']) ? $_REQUEST['form-errors'] : [],
                ]
            );
        }

        /**
         * Handles a POST request to add a new Role.
         */
        public function handle_add_role()
        {
            if (!isset($_POST['submit'], $_POST['form-action'], $_GET['page'], $_GET['module'])
                || ($_GET['page'] != PP_Modules_Settings::SETTINGS_SLUG && $_GET['module'] != self::SETTINGS_SLUG) || $_POST['form-action'] != 'add-role') {
                return;
            }

            if (!wp_verify_nonce($_POST['_wpnonce'], 'manage-role')) {
                wp_die($this->module->messages['nonce-failed']);
            }

            if (!current_user_can($this->cap_manage_roles)) {
                wp_die($this->module->messages['invalid-permissions']);
            }

            // Sanitize all of the user-entered values
            $name         = sanitize_title(strip_tags(trim($_POST['name'])));
            $display_name = stripslashes(strip_tags(trim($_POST['display_name'])));
            $users        = $_POST['users'];

            $_REQUEST['form-errors'] = [];

            /**
             * Form validation for adding new Role
             *
             * Details
             * - 'name' is a required field, but can't match an existing name or slug. Needs to be 40 characters or less
             * - 'display_name' is a required field
             */
            // Field is required
            if (empty($name)) {
                $_REQUEST['form-errors']['name'] = __('Please enter a name for the role.', 'publishpress');
            }

            // Check to ensure a role with the same name doesn't exist
            $role = get_role($name);
            if (!empty($role)) {
                $_REQUEST['form-errors']['name'] = __('Name already in use. Please choose another.', 'publishpress');
            }

            if (strlen($name) > 40) {
                $_REQUEST['form-errors']['name'] = __('Role name cannot exceed 40 characters. Please try a shorter name.',
                    'publishpress');
            }

            if (empty($display_name)) {
                $_REQUEST['form-errors']['display_name'] = __('Please enter a display name for the role.',
                    'publishpress');
            }

            if (strlen($display_name) > 40) {
                $_REQUEST['form-errors']['display_name'] = __('Role\'s display name cannot exceed 40 characters. Please try a shorter name.',
                    'publishpress');
            }

            // Kick out if there are any errors
            if (count($_REQUEST['form-errors'])) {
                $_REQUEST['error'] = 'form-error';

                return;
            }

            // Try to add the role
            $role = $this->addRole($name, $display_name, []);
            if (is_wp_error($role)) {
                wp_die(__('Error adding role.', 'publishpress'));
            }

            // Check if we have to add users to this role.
            if (!empty($users)) {
                foreach ($users as $user_id) {
                    $user = get_user_by('ID', (int)$user_id);
                    $user->add_role($name);
                }
            }

            $args         = [
                'action'  => 'edit-role',
                'role-id' => $role->name,
                'message' => 'role-added',
            ];
            $redirect_url = $this->getLink($args);
            wp_redirect($redirect_url);
            exit;
        }

        /**
         * Handles a POST request to edit a Role.
         */
        public function handle_edit_role()
        {
            if (!isset($_POST['submit'], $_POST['form-action'], $_GET['page'], $_GET['module'])
                || ($_GET['page'] != PP_Modules_Settings::SETTINGS_SLUG && $_GET['module'] != self::SETTINGS_SLUG) || $_POST['form-action'] != 'edit-role') {
                return;
            }

            if (!wp_verify_nonce($_POST['_wpnonce'], 'manage-role')) {
                wp_die($this->module->messages['nonce-failed']);
            }

            if (!current_user_can($this->cap_manage_roles)) {
                wp_die($this->module->messages['invalid-permissions']);
            }

            // Sanitize all of the user-entered values
            $name         = sanitize_title(strip_tags(trim($_POST['role-id'])));
            $display_name = stripslashes(strip_tags(trim($_POST['display_name'])));
            $users        = $_POST['users'];

            $_REQUEST['form-errors'] = [];

            /**
             * Form validation for adding new Role
             *
             * Details
             * - 'name' is a required field, but can't match an existing name or slug. Needs to be 40 characters or less
             * - 'display_name' is a required field
             */
            // Field is required
            if (empty($display_name)) {
                $_REQUEST['form-errors']['name'] = __('Please enter a display name for the role.', 'publishpress');
            }

            if (strlen($display_name) > 40) {
                $_REQUEST['form-errors']['name'] = __('Role\'s display name cannot exceed 40 characters. Please try a shorter name.',
                    'publishpress');
            }

            // Kick out if there are any errors
            if (count($_REQUEST['form-errors'])) {
                $_REQUEST['error'] = 'form-error';

                return;
            }

            // Get all the roles and edit the current role. Saving all the roles again in the options table.
            $roles = get_option('wp_user_roles');
            if (is_wp_error($role) || empty($roles)) {
                wp_die(__('Error loading role.', 'publishpress'));
            }

            if (!isset($roles[$name])) {
                wp_die(__('Role not found. Can\'t edit.', 'publishpress'));
            }

            $roles[$name]['name'] = $display_name;

            update_option('wp_user_roles', $roles);

            // Check if we have to remove users from this role.
            $users_in_the_role = get_users(
                [
                    'role' => $name,
                ]
            );

            if (!empty($users_in_the_role)) {
                foreach ($users_in_the_role as $user) {
                    if (!in_array($user->ID, $users)) {
                        $user->remove_role($name);
                    }
                }
            }

            // Check if we have to add users to this role.
            if (!empty($users)) {
                foreach ($users as $user_id) {
                    $user = get_user_by('ID', (int)$user_id);
                    $user->add_role($name);
                }
            }

            $args         = [
                'action'  => 'edit-role',
                'role-id' => $name,
                'message' => 'role-updated',
            ];
            $redirect_url = $this->getLink($args);
            wp_redirect($redirect_url);
            exit;
        }

        /**
         * Handles a POST request to delete a Role.
         */
        public function handle_delete_role()
        {
            if (!isset($_GET['action'], $_GET['page'], $_GET['module'])
                || ($_GET['page'] != PP_Modules_Settings::SETTINGS_SLUG && $_GET['module'] != self::SETTINGS_SLUG) || $_GET['action'] != 'delete-role') {
                return;
            }

            if (!wp_verify_nonce($_GET['nonce'], 'manage-role')) {
                wp_die($this->module->messages['nonce-failed']);
            }

            if (!current_user_can($this->cap_manage_roles)) {
                wp_die($this->module->messages['invalid-permissions']);
            }

            // Sanitize all of the user-entered values
            $name = sanitize_title(strip_tags(trim($_GET['role-id'])));

            // Check if the role exists
            $role = get_role($name);

            if (empty($role)) {
                wp_die(__('Role not found. Can\'t edit.', 'publishpress'));
            }

            remove_role($name);

            $args         = [
                'action'  => 'add-role',
                'message' => 'role-deleted',
            ];
            $redirect_url = $this->getLink($args);
            wp_redirect($redirect_url);
            exit;
        }

        /**
         * Register settings for notifications so we can partially use the Settings API
         * (We use the Settings API for form generation, but not saving)
         */
        public function register_settings()
        {

        }
    }
}