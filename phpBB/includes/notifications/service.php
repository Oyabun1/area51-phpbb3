<?php
/**
*
* @package notifications
* @copyright (c) 2012 phpBB Group
* @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
*
*/

use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
* @ignore
*/
if (!defined('IN_PHPBB'))
{
	exit;
}

/**
* Notifications service class
* @package notifications
*/
class phpbb_notifications_service
{
	protected $phpbb_container;
	protected $db;

	/**
	* Users loaded from the DB
	*
	* @var array Array of user data that we've loaded from the DB
	*/
	protected $users = array();

	public function __construct(ContainerBuilder $phpbb_container)
	{
		$this->phpbb_container = $phpbb_container;

		// Some common things we're going to use
		$this->db = $phpbb_container->get('dbal.conn');
	}

	/**
	* Load the user's notifications
	*
	* @param array $options Optional options to control what notifications are loaded
	*				user_id		User id to load notifications for (Default: $user->data['user_id'])
	*				order_by	Order by (Default: time)
	*				order_dir	Order direction (Default: DESC)
	* 				limit		Number of notifications to load (Default: 5)
	* 				start		Notifications offset (Default: 0)
	*/
	public function load_notifications($options = array())
	{
		$user = $this->phpbb_container->get('user');

		// Merge default options
		$options = array_merge(array(
			'user_id'		=> $user->data['user_id'],
			'order_by'		=> 'time',
			'order_dir'		=> 'DESC',
			'limit'			=> 5,
			'start'			=> 0,
		), $options);

		$notifications = $user_ids = array();
		$load_special = array();

		$sql = 'SELECT *
			FROM ' . NOTIFICATIONS_TABLE . '
			WHERE user_id = ' . (int) $options['user_id'] . '
				ORDER BY ' . $this->db->sql_escape($options['order_by']) . ' ' . $this->db->sql_escape($options['order_dir']);
		$result = $this->db->sql_query_limit($sql, $options['limit'], $options['start']);

		while ($row = $this->db->sql_fetchrow($result))
		{
			$item_type_class_name = $this->get_item_type_class_name($row['item_type'], true);

			$notification = new $item_type_class_name($this->phpbb_container, $row);

			// Array of user_ids to query all at once
			$user_ids = array_merge($user_ids, $notification->users_to_query());

			// Some notification types also require querying additional tables themselves
			if (!isset($load_special[$row['item_type']]))
			{
				$load_special[$row['item_type']] = array();
			}
			$load_special[$row['item_type']] = array_merge($load_special[$row['item_type']], $notification->get_load_special());

			$notifications[] = $notification;
		}
		$this->db->sql_freeresult($result);

		$this->load_users($user_ids);

		// Allow each type to load it's own special items
		foreach ($load_special as $item_type => $data)
		{
			$item_type_class_name = $this->get_item_type_class_name($item_type, true);

			$item_type_class_name::load_special($this->phpbb_container, $data, $notifications);
		}

		return $notifications;
	}

	/**
	* Add a notification
	*
	* @param string $item_type Type identifier
	* @param array $data Data specific for this type that will be inserted
	*/
	public function add_notifications($item_type, $data)
	{
		$item_type_class_name = $this->get_item_type_class_name($item_type);

		$item_id = $item_type_class_name::get_item_id($data);

		// Update any existing notifications for this item
		$this->update_notifications($item_type, $data);

		// find out which users want to receive this type of notification
		$notify_users = $item_type_class_name::find_users_for_notification($this->phpbb_container, $data);

		$this->add_notifications_for_users($item_type, $data, $notify_users);
	}

	/**
	* Add a notification for specific users
	*
	* @param string $item_type Type identifier
	* @param array $data Data specific for this type that will be inserted
	* @param array $notify_users User list to notify
	*/
	public function add_notifications_for_users($item_type, $data, $notify_users)
	{
		$item_type_class_name = $this->get_item_type_class_name($item_type);

		$item_id = $item_type_class_name::get_item_id($data);

		$user_ids = array();
		$notification_objects = $notification_methods = array();
		$new_rows = array();

		// Never send notifications to the anonymous user or the current user!
		unset($notify_users[ANONYMOUS], $notify_users[$this->phpbb_container->get('user')->data['user_id']]);

		// Make sure not to send new notifications to users who've already been notified about this item
		// This may happen when an item was added, but now new users are able to see the item
		// todo Users should not receive notifications from multiple events from the same item (ex: for a topic reply with a quote including your username)
		//		Probably should be handled within each type?
		$sql = 'SELECT user_id
			FROM ' . NOTIFICATIONS_TABLE . "
			WHERE item_type = '" . $this->db->sql_escape($item_type) . "'
				AND item_id = " . (int) $item_id;
		$result = $this->db->sql_query($sql);
		while ($row = $this->db->sql_fetchrow($result))
		{
			unset($notify_users[$row['user_id']]);
		}
		$this->db->sql_freeresult($result);

		if (!sizeof($notify_users))
		{
			return;
		}

		// Go through each user so we can insert a row in the DB and then notify them by their desired means
		foreach ($notify_users as $user => $methods)
		{
			$notification = new $item_type_class_name($this->phpbb_container);

			$notification->user_id = (int) $user;

			// Store the creation array in our new rows that will be inserted later
			$new_rows[] = $notification->create_insert_array($data);

			// Users are needed to send notifications
			$user_ids = array_merge($user_ids, $notification->users_to_query());

			foreach ($methods as $method)
			{
				// setup the notification methods and add the notification to the queue
				if ($method) // blank means we just insert it as a notification, but do not notify them by any other means
				{
					if (!isset($notification_methods[$method]))
					{
						$method_class_name = 'phpbb_notifications_method_' . $method;
						$notification_methods[$method] = new $method_class_name($this->phpbb_container);
					}

					$notification_methods[$method]->add_to_queue($notification);
				}
			}
		}

		// insert into the db
		$this->db->sql_multi_insert(NOTIFICATIONS_TABLE, $new_rows);

		// We need to load all of the users to send notifications
		$this->load_users($user_ids);

		// run the queue for each method to send notifications
		foreach ($notification_methods as $method)
		{
			$method->notify();
		}
	}

	/**
	* Update a notification
	*
	* @param string $item_type Type identifier
	* @param array $data Data specific for this type that will be updated
	*/
	public function update_notifications($item_type, $data)
	{
		$item_type_class_name = $this->get_item_type_class_name($item_type);

		// Allow the notifications class to over-ride the update_notifications functionality
		if (method_exists($item_type_class_name, 'update_notifications'))
		{
			// Return False to over-ride the rest of the update
			if ($item_type_class_name::update_notifications($this->phpbb_container, $data) === false)
			{
				return;
			}
		}

		$item_id = $item_type_class_name::get_item_id($data);

		$notification = new $item_type_class_name($this->phpbb_container);
		$update_array = $notification->create_update_array($data);

		$sql = 'UPDATE ' . NOTIFICATIONS_TABLE . '
			SET ' . $this->db->sql_build_array('UPDATE', $update_array) . "
			WHERE item_type = '" . $this->db->sql_escape($item_type) . "'
				AND item_id = " . (int) $item_id;
		$this->db->sql_query($sql);
	}

	/**
	* Delete a notification
	*
	* @param string $item_type Type identifier
	* @param int|array $item_id Identifier within the type (or array of ids)
	* @param array $data Data specific for this type that will be updated
	*/
	public function delete_notifications($item_type, $item_id)
	{
		$sql = 'DELETE FROM ' . NOTIFICATIONS_TABLE . "
			WHERE item_type = '" . $this->db->sql_escape($item_type) . "'
				AND " . (is_array($item_id) ? $this->db->sql_in_set('item_id', $item_id) : 'item_id = ' . (int) $item_id);
		$this->db->sql_query($sql);
	}

	public function add_subscription($item_type, $item_id, $method = '')
	{
		$this->get_item_type_class_name($item_type);

		$sql = 'INSERT INTO ' . USER_NOTIFICATIONS_TABLE . ' ' .
			$this->db->sql_build_array('INSERT', array(
				'item_type'		=> $item_type,
				'item_id'		=> (int) $item_id,
				'user_id'		=> $this->phpbb_container->get('user')->data['user_id'],
				'method'		=> $method,
			));
		$this->db->sql_query($sql);
	}

	/**
	* Load user helper
	*
	* @param array $user_ids
	*/
	public function load_users($user_ids)
	{
		// Load the users
		$user_ids = array_unique($user_ids);

		// Do not load users we already have in $this->users
		$user_ids = array_diff($user_ids, array_keys($this->users));

		if (sizeof($user_ids))
		{
			$sql = 'SELECT *
				FROM ' . USERS_TABLE . '
				WHERE ' . $this->db->sql_in_set('user_id', $user_ids);
			$result = $this->db->sql_query($sql);

			while ($row = $this->db->sql_fetchrow($result))
			{
				$this->users[$row['user_id']] = $row;
			}
			$this->db->sql_freeresult($result);
		}
	}

	/**
	* Get a user row from our users cache
	*
	* @param int $user_id
	* @return array
	*/
	public function get_user($user_id)
	{
		return $this->users[$user_id];
	}

	/**
	* Helper to get the notifications item type class name and clean it if unsafe
	*/
	private function get_item_type_class_name(&$item_type, $safe = false)
	{
		if (!$safe)
		{
			$item_type = preg_replace('#[^a-z]#', '', $item_type);
		}

		return 'phpbb_notifications_type_' . $item_type;
	}
}
