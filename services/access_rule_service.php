<?php

class AccessRuleService {
	/**
	 * Build AccessOption objects from POST payload format.
	 * @param array $access_option_payload
	 * @return array list of AccessOption
	 */
	public function build_access_options_from_payload(array $access_option_payload) {
		$options = array();

		foreach($access_option_payload as $key => $value) {
			if(!isset($value['enabled'])) {
				continue;
			}

			$option = new AccessOption();
			$option->option = $key;
			$option->value = isset($value['value']) ? $value['value'] : null;
			$options[] = $option;
		}

		return $options;
	}

	/**
	 * Create access for source entity onto target account.
	 * @param ServerAccount $account
	 * @param Entity $entity
	 * @param array $access_options
	 */
	public function add_access(ServerAccount $account, Entity $entity, array $access_options) {
		$account->add_access($entity, $access_options);
	}

	/**
	 * Create access for source entity onto target group.
	 * @param Group $group
	 * @param Entity $entity
	 * @param array $access_options
	 */
	public function add_group_access(Group $group, Entity $entity, array $access_options) {
		$group->add_access($entity, $access_options);
	}

	/**
	 * Resolve and delete access by identifier from a pre-fetched access list.
	 * @param ServerAccount $account
	 * @param array $account_access list of Access objects
	 * @param mixed $access_id identifier from request payload
	 */
	public function delete_access_by_id(ServerAccount $account, array $account_access, $access_id) {
		$access = $this->find_access_by_id($account_access, $access_id);
		if($access instanceof Access) {
			$account->delete_access($access);
		}
	}

	/**
	 * Resolve and delete group access by identifier from a pre-fetched access list.
	 * @param Group $group
	 * @param array $group_access list of Access objects
	 * @param mixed $access_id identifier from request payload
	 */
	public function delete_group_access_by_id(Group $group, array $group_access, $access_id) {
		$access = $this->find_access_by_id($group_access, $access_id);
		if($access instanceof Access) {
			$group->delete_access($access);
		}
	}

	/**
	 * @param array $account_access list of Access objects
	 * @param mixed $access_id
	 * @return Access|null
	 */
	public function find_access_by_id(array $account_access, $access_id) {
		$target_id = (int)$access_id;
		foreach($account_access as $access) {
			if($access instanceof Access && (int)$access->id === $target_id) {
				return $access;
			}
		}

		return null;
	}

	/**
	 * @param array $access_requests list of AccessRequest objects
	 * @param mixed $request_id
	 * @return AccessRequest|null
	 */
	public function find_access_request_by_id(array $access_requests, $request_id) {
		$target_id = (int)$request_id;
		foreach($access_requests as $request) {
			if($request instanceof AccessRequest && (int)$request->id === $target_id) {
				return $request;
			}
		}

		return null;
	}
}
