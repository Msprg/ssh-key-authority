<?php

class RelationLifecycleService {
	/**
	 * Resolve an entity by user uid or group name.
	 * @param UserDirectory $user_dir
	 * @param GroupDirectory $group_dir
	 * @param string $name
	 * @return Entity|null
	 */
	public function resolve_user_or_group_by_name(UserDirectory $user_dir, GroupDirectory $group_dir, $name) {
		try {
			return $user_dir->get_user_by_uid($name);
		} catch(UserNotFoundException $e) {
			try {
				return $group_dir->get_group_by_name($name);
			} catch(GroupNotFoundException $e) {
				return null;
			}
		}
	}

	/**
	 * @param array $entities
	 * @param int|string $entity_id
	 * @return Entity|null
	 */
	public function find_entity_by_id(array $entities, int|string $entity_id): ?Entity {
		$target_id = (string)$entity_id;
		foreach($entities as $entity) {
			if($entity instanceof Entity && (string)$entity->id === $target_id) {
				return $entity;
			}
		}

		return null;
	}

	/**
	 * @param array $entities
	 * @param int|string $entity_id
	 * @return Entity|null
	 */
	public function find_entity_by_entity_id(array $entities, int|string $entity_id): ?Entity {
		$target_id = (string)$entity_id;
		foreach($entities as $entity) {
			if($entity instanceof Entity && (string)$entity->entity_id === $target_id) {
				return $entity;
			}
		}

		return null;
	}

	/**
	 * @param Server $server
	 * @param array $admins
	 * @param mixed $admin_id
	 */
	public function delete_server_admin_by_id(Server $server, array $admins, $admin_id) {
		$admin = $this->find_entity_by_id($admins, $admin_id);
		if($admin instanceof Entity) {
			$server->delete_admin($admin);
		}
	}

	/**
	 * Add server leader relation.
	 * @param Server $server
	 * @param Entity $entity
	 * @param bool $send_mail
	 * @return bool true when relation inserted, false when already present
	 */
	public function add_server_admin(Server $server, Entity $entity, $send_mail = true) {
		$this->assert_server_admin_entity_supported($entity);
		return $server->add_admin($entity, $send_mail);
	}

	/**
	 * Remove server leader relation.
	 * @param Server $server
	 * @param Entity $entity
	 * @return bool true when relation removed, false when relation absent
	 */
	public function delete_server_admin(Server $server, Entity $entity) {
		$this->assert_server_admin_entity_supported($entity);
		return $server->delete_admin($entity);
	}

	/**
	 * Reassign leaders for selected servers.
	 * @param array $servers list of Server objects
	 * @param array $selected_server_hostnames list of hostname strings
	 * @param Entity $from_admin
	 * @param Entity $to_admin
	 */
	public function reassign_server_admins_by_hostname(array $servers, array $selected_server_hostnames, Entity $from_admin, Entity $to_admin): void {
		$errors = array();
		foreach($servers as $server) {
			if(!($server instanceof Server)) {
				continue;
			}
			if(in_array($server->hostname, $selected_server_hostnames, true)) {
				try {
					$this->add_server_admin($server, $to_admin);
					$this->delete_server_admin($server, $from_admin);
				} catch(Throwable $error) {
					$errors[] = "Server {$server->hostname}: {$error->getMessage()}";
				}
			}
		}
		foreach($errors as $error) {
			error_log('reassign_server_admins_by_hostname: '.$error);
		}
	}

	/**
	 * @param array $servers list of Server objects
	 * @param Entity $entity
	 * @param bool $send_mail
	 * @return array affected Server objects
	 */
	public function add_server_admin_bulk(array $servers, Entity $entity, $send_mail = false) {
		$affected_servers = array();
		foreach($servers as $server) {
			if(!($server instanceof Server)) {
				continue;
			}
			if($this->add_server_admin($server, $entity, $send_mail)) {
				$affected_servers[] = $server;
			}
		}

		return $affected_servers;
	}

	/**
	 * @param array $servers list of Server objects
	 * @param Entity $entity
	 * @return array affected Server objects
	 */
	public function delete_server_admin_bulk(array $servers, Entity $entity) {
		$affected_servers = array();
		foreach($servers as $server) {
			if(!($server instanceof Server)) {
				continue;
			}
			if($this->delete_server_admin($server, $entity)) {
				$affected_servers[] = $server;
			}
		}

		return $affected_servers;
	}

	/**
	 * @param ServerAccount $account
	 * @param array $admins
	 * @param mixed $admin_id
	 */
	public function delete_server_account_admin_by_id(ServerAccount $account, array $admins, $admin_id) {
		$admin = $this->find_entity_by_id($admins, $admin_id);
		if($admin instanceof User) {
			$account->delete_admin($admin);
		}
	}

	/**
	 * @param Group $group
	 * @param array $admins
	 * @param mixed $admin_id
	 */
	public function delete_group_admin_by_id(Group $group, array $admins, $admin_id) {
		$admin = $this->find_entity_by_id($admins, $admin_id);
		if($admin instanceof User) {
			$group->delete_admin($admin);
		}
	}

	/**
	 * @param Group $group
	 * @param array $members
	 * @param mixed $member_entity_id
	 */
	public function delete_group_member_by_entity_id(Group $group, array $members, $member_entity_id) {
		$member = $this->find_entity_by_entity_id($members, $member_entity_id);
		if($member instanceof Entity && !$group->system) {
			$group->delete_member($member);
		}
	}

	/**
	 * @param Entity $entity
	 */
	private function assert_server_admin_entity_supported(Entity $entity) {
		if(!($entity instanceof User) && !($entity instanceof Group)) {
			throw new InvalidArgumentException('Only user or group entities can be server leaders');
		}
	}
}
