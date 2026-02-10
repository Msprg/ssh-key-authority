<?php

class KeyLifecycleService {
	/**
	 * Import and attach a public key for a user entity.
	 * @param User $user
	 * @param string $raw_public_key
	 */
	public function add_user_public_key(User $user, $raw_public_key) {
		$public_key = new PublicKey;
		$public_key->import($raw_public_key, $user->uid);
		$user->add_public_key($public_key);
	}

	/**
	 * Import and attach a public key for a server account entity.
	 * @param ServerAccount $account
	 * @param string $raw_public_key
	 * @param bool $allow_weak_key force key import for admins
	 */
	public function add_server_account_public_key(ServerAccount $account, $raw_public_key, $allow_weak_key = false) {
		$public_key = new PublicKey;
		$public_key->import($raw_public_key, null, $allow_weak_key);
		$account->add_public_key($public_key);
	}

	/**
	 * Resolve and delete a public key by identifier from a pre-fetched list.
	 * @param Entity $entity
	 * @param array $existing_public_keys list of PublicKey objects
	 * @param mixed $public_key_id identifier from request payload
	 */
	public function delete_entity_public_key(Entity $entity, array $existing_public_keys, $public_key_id) {
		$public_key = $this->find_public_key_by_id($existing_public_keys, $public_key_id);
		if($public_key instanceof PublicKey) {
			$entity->delete_public_key($public_key);
		}
	}

	/**
	 * @param array $existing_public_keys list of PublicKey objects
	 * @param mixed $public_key_id
	 * @return PublicKey|null
	 */
	public function find_public_key_by_id(array $existing_public_keys, $public_key_id) {
		foreach($existing_public_keys as $public_key) {
			if($public_key instanceof PublicKey && $public_key->id == $public_key_id) {
				return $public_key;
			}
		}

		return null;
	}
}
