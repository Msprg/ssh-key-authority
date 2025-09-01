#!/usr/bin/php
<?php
##
## Copyright 2013-2017 Opera Software AS
## Modifications Copyright 2021 Leitwerk AG
##
## Licensed under the Apache License, Version 2.0 (the "License");
## you may not use this file except in compliance with the License.
## You may obtain a copy of the License at
##
## http://www.apache.org/licenses/LICENSE-2.0
##
## Unless required by applicable law or agreed to in writing, software
## distributed under the License is distributed on an "AS IS" BASIS,
## WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
## See the License for the specific language governing permissions and
## limitations under the License.
##

chdir(__DIR__);
require('../core.php');

// Fetch users from local database
$users = $user_dir->list_users();

// DEBUG: Show the initial list of users from the database
echo "===========================================\n";
echo "=== Initial Users from Database ==========\n";
echo "===========================================\n";
if (empty($users)) {
    echo "No users found in the database.\n";
} else {
    echo "Found " . count($users) . " users in the database.\n";
    // Optionally print more details, can be very verbose:
//     print_r($users);
}
echo "===========================================\n\n";

// Use 'keys-sync' user as the active user
$active_user = User::get_keys_sync_user();

try {
        $sysgrp = $group_dir->get_group_by_name($config['ldap']['admin_group_cn']);
} catch(GroupNotFoundException $e) {
        $sysgrp = new Group;
        $sysgrp->name = $config['ldap']['admin_group_cn'];
        $sysgrp->system = 1;
        $group_dir->add_group($sysgrp);
}
// Add guid of main admin group, if not already stored
if ($sysgrp->ldap_guid === null) {
        $sysgrp_ldap = $ldap->search($config['ldap']['dn_group'],
                        LDAP::escape($config['ldap']['group_id']).'='.LDAP::escape($sysgrp->name),
                        [strtolower($config['ldap']['group_num'])]);
        if (!empty($sysgrp_ldap)) {
                $sysgrp->ldap_guid = $sysgrp_ldap[0][strtolower($config['ldap']['group_num'])];
                $sysgrp->update();
        }
}

// Process each user
echo "===========================================\n";
echo "=== Processing Individual Users ==========\n";
echo "===========================================\n";
$user_counter = 0;
foreach($users as $user) {
        $user_counter++;
        // DEBUG: Show details of the user being processed in this iteration
        echo "\n--- Processing User #{$user_counter}: {$user->uid} ({$user->name}) ---\n";
        echo "  Local DB ID: " . (isset($user->id) ? $user->id : 'N/A') . "\n";
        echo "  Auth Realm: " . ($user->auth_realm) . "\n";
        echo "  Is user account enabled? (Before Sync): " . ($user->active) . "\n";
        // You could print the whole object, but it might be large:
        // echo "  User Object (Before Sync): "; print_r($user); echo "\n";

        if($user->auth_realm == 'LDAP') {
                echo "  User Realm is LDAP. Attempting sync...\n";
                $active = $user->active;
                try {
                        $user->get_details_from_ldap();
                        $user->update();
                        echo "  LDAP details retrieved and user updated locally.\n";
                        if(isset($config['ldap']['user_superior'])) {
                                $user->get_superior_from_ldap();
                                echo "  LDAP superior retrieved.\n";
                        }
                        // DEBUG: Show active status after sync
                        echo "  Is user account enabled? (After Sync): " . ($user->active) . "\n";

                } catch(UserNotFoundException $e) {
                        echo "  User NOT found in LDAP. Setting inactive.\n";
                        $user->active = 0;
                }
                $user->update_group_memberships();
                echo "  Group memberships updated.\n";
                $user->update();
                if($active && !$user->active) {
                        echo "  User deactivated during sync. Checking for orphaned servers...\n";
                        // Check for servers that will now be leader-less
                        $servers = $user->list_admined_servers();
                        foreach($servers as $server) {
                                $server_admins = $server->list_effective_admins();
                                $total_server_admins = 0;
                                foreach($server_admins as $server_admin) {
                                        if($server_admin->active) $total_server_admins++;
                                }
                                if($total_server_admins == 0) {
                                        if(isset($config['ldap']['user_superior'])) {
                                                $rcpt = $user->superior;
                                                while(!is_null($rcpt) && !$rcpt->active) {
                                                        $rcpt = $rcpt->superior;
                                                }
                                        }
                                        $email = new Email;
                                        $email->subject = "Server {$server->hostname} has been orphaned";
                                        $email->body = "{$user->name} ({$user->uid}) was a leader for {$server->hostname}, but they have now been marked as a former employee and there are no active leaders remaining for this server.\n\n";
                                        $email->body .= "Please find a replacement owner for this server and inform {$config['email']['admin_address']} ASAP, otherwise the server will be registered for decommissioning.";
                                        $email->add_reply_to($config['email']['admin_address'], $config['email']['admin_name']);
                                        if(!isset($rcpt)) {
                                                $email->subject .= " - NO SUPERIOR EMPLOYEE FOUND";
                                                $email->body .= "\n\nWARNING: No suitable superior employee could be found!";
                                                $email->add_recipient($config['email']['report_address'], $config['email']['report_name']);
                                        } else {
                                                $email->add_recipient($rcpt->email, $rcpt->name);
                                                $email->add_cc($config['email']['report_address'], $config['email']['report_name']);
                                        }
                                        $email->send();
                                }
                        }
                        echo "  Orphan check complete.\n";
                }
        } else {
             echo "  User Realm is not LDAP. Skipping LDAP sync.\n";
        }
}
echo "===========================================\n\n";


// Update group names
echo "===========================================\n";
echo "=== Initial Groups from Database =========\n";
echo "===========================================\n";
$groups = $group_dir->list_groups();
// DEBUG: Show the initial list of groups from the database
if (empty($groups)) {
    echo "No groups found in the database.\n";
} else {
    echo "Found " . count($groups) . " groups in the database.\n";
    // Optionally print more details, can be very verbose:
//     print_r($groups);
}
echo "===========================================\n\n";


echo "===========================================\n";
echo "=== Processing Individual Groups ==========\n";
echo "===========================================\n";
$group_counter = 0;
foreach ($groups as $group) {
        $group_counter++;
        // DEBUG: Show details of the group being processed
        echo "\n--- Processing Group #{$group_counter}: {$group->name} ---\n";
        echo "  Local DB ID: " . (isset($group->id) ? $group->id : 'N/A') . "\n";
        echo "  LDAP GUID: " . ($group->ldap_guid !== null ? $group->ldap_guid : 'None') . "\n";
        echo "  System Group: " . (isset($group->system) ? ($group->system ? 'Yes' : 'No') : 'N/A') . "\n";
        // You could print the whole object:
        // echo "  Group Object (Before Sync): "; print_r($group); echo "\n";

        if ($group->ldap_guid !== null) {
                echo "  Group has LDAP GUID. Attempting name sync...\n";
                try {
                    // DEBUG: Show the search parameters for the group name lookup
                    $search_filter = LDAP::escape($config['ldap']['group_num']).'='.LDAP::query_encode_guid($group->ldap_guid);
                    echo "  Searching Base DN: {$config['ldap']['dn_group']}\n";
                    echo "  Searching Filter: {$search_filter}\n";
                    echo "  Requesting Attribute: name\n";

                    $group_ldap = $ldap->search($config['ldap']['dn_group'], $search_filter, ['name']);

                    if (!empty($group_ldap) && isset($group_ldap[0]["name"])) {
                            $original_name = $group->name;
                            $ldap_name = $group_ldap[0]["name"];
                            if ($original_name !== $ldap_name) {
                                echo "  Found name in LDAP: '{$ldap_name}'. Updating from '{$original_name}'.\n";
                                $group->name = $ldap_name;
                                $group->update();
                            } else {
                                echo "  Name in LDAP ('{$ldap_name}') matches local name. No update needed.\n";
                            }
                    } else {
                        echo "  Group with GUID {$group->ldap_guid} not found in LDAP or 'name' attribute missing.\n";
                    }
                } catch (Exception $e) {
                    // Catch potential exceptions during LDAP search for groups
                    echo "  ERROR searching for group '{$group->name}' (GUID: {$group->ldap_guid}) in LDAP: " . $e->getMessage() . "\n";
                }
        } else {
            echo "  Group does not have an LDAP GUID. Skipping name sync.\n";
        }
}
echo "===========================================\n";
echo "=== LDAP Update Script Finished ==========\n";
echo "===========================================\n";

?>
