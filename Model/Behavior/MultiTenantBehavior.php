<?php
App::uses('ModelBehavior', 'Model');
class MultiTenantBehavior extends ModelBehavior {

/**
 * Default settings
 *
 * @var array
 */
	protected $_defaultSettings = array(
		'scope' => array()
	);

/**
 * Initialize behavior
 *
 * @param Model $Model instance of model
 * @param array $settings array of configuration settings
 * @return void
 */
	public function setup(Model $Model, $settings = array()) {
		$this->settings[$Model->alias] = array_merge($this->_defaultSettings, $settings);
	}

/**
 * Change scope of find-, save- and delete-operations.
 * If Model has TreeBehavior attached its scope will also be changed.
 *
 * @param Model $Model instance of model
 * @param array $conditions The new scope or leave empty to return the current scope
 * @return array Returns set scope
 */
	public function scope(Model $Model, array $conditions = array()) {
		if ($conditions) {
			return $this->settings[$Model->alias]['scope'] = $conditions;
		}

		$scope = array();
		foreach ($this->settings[$Model->alias]['scope'] as $key => $value) {
			list($alias, $field) = $this->_splitKey($Model, $key);
			if ($alias === $Model->alias) {
				if (!$Model->hasField($field)) {
					continue;
				}
			} else {
				if (!isset($Model->{$alias})) {
					throw new RuntimeException(sprintf('Model "%s" is not associated with model "%s"', $Model->alias, $alias));
				}
				if (!$Model->{$alias}->hasField($field)) {
					continue;
				}
			}
			if (is_callable($value)) {
				$value = $value();
			}
			$scope[$alias . '.' . $field] = $value;
		}

		if ($Model->Behaviors->loaded('Tree') && is_a($Model->Behaviors->Tree, 'TreeBehavior')) {
			$Model->Behaviors->Tree->settings[$Model->alias]['scope'] = $scope;
		}
		return $scope;
	}

/**
 * Add conditions to scope
 *
 * @param Model $Model instance of model
 * @param string $key
 * @param string $value
 * @return array Returns the new scope
 */
	public function addToScope(Model $Model, array $conditions) {
		return $this->scope($Model, Hash::merge($this->settings[$Model->alias]['scope'], $conditions));
	}

/**
 * Inject scope values into find query
 * If Containable is loaded and scope contains conditions for
 * associated models they are automatically added to the 'contain'-key.
 *
 * @param Model $Model instance of model
 * @param array $query
 * @return array Returns the modified query
 */
	public function beforeFind(Model $Model, $query) {
		if ($Model->alias === 'PaymentMethod') {
			pr('BEFORE');
			pr($query);
		}

		$conditions = $this->scope($Model);
		foreach ($conditions as $key => $value) {
			$query = $this->_addCondition($Model, $query, $key, $value);
		}
		if ($Model->alias === 'PaymentMethod') {
			pr('AFTER');
			pr($query);
		}
		return $query;
	}

/**
 * Inject scope values into insert query
 *
 * @param Model $Model instance of model
 * @param array $options
 * @return boolean Always returns true
 */
	public function beforeSave(Model $Model, $options = array()) {
		$conditions = $this->scope($Model);
		foreach ($conditions as $key => $value) {
			$Model->data = $this->_addData($Model, $Model->data, $key, $value);
		}
		return true;
	}

/**
 * Check record is within set scope before allowing delete
 *
 * @param Model $Model instance of model
 * @param boolean $cascade
 * @return boolean Returns true if record is within the set scope, false otherwise
 */
	public function beforeDelete(Model $Model, $cascade = true) {
		$exists = $Model->hasAny(array($Model->alias . '.' . $Model->primaryKey => $Model->id));
		if (!$exists) {
			return false;
		}
		return true;
	}

/**
 * Helper method which modifies query, adding and removing conditions according to set scope and set conditiosn.
 * Automatically adds associated models to 'contain'-key if ContainableBehavior is loaded
 *
 * @param Model $Model instance of model
 * @param array $query The query to modify
 * @param string $key A query key. E.g. Model.field
 * @return string $value Value for query key
 */
	protected function _addCondition(Model $Model, $query, $key, $value) {
		if (!isset($query['conditions']) || !is_array($query['conditions'])) {
			return $query;
		}

		list($alias, $field) = $this->_splitKey($Model, $key);

		// Check if condition is already present in $query
		$needles = array($key, $field);
		foreach ($needles as $needle) {
			// If condition is already set return $query unmodified (unless the value is false)
			if ($this->_array_key_exists_recursive($needle, $query['conditions'])) {
				// Unset condition from $query if value is false
				if (isset($query['conditions'][$needle]) && $query['conditions'][$needle] === false) {
					unset($query['conditions'][$needle]);
				}
				return $query;
			}
		}

		// Don't add conditions to query when the value is false
		if ($value === false) {
			return $query;
		}

		$query['conditions'][$alias . '.' . $field] = $value;
		if ($Model->Behaviors->loaded('Containable') && $alias !== $Model->alias) {
			$query = $this->_addContainment($Model, $query, $alias);
		}
		return $query;
	}

/**
 * Helper method which adds associated models used in scope to the 'contain'-key
 *
 * @param Model $Model instance of model
 * @param array $query The query to modify
 * @param string $alias The model alias to add as a containment
 * @return array Returns modified query with added containments
 */
	protected function _addContainment(Model $Model, $query, $alias) {
		$contain = array();
		if (isset($query['contain'])) {
			$contain = Hash::normalize((array)$query['contain']);
		}
		if (!isset($contain[$alias])) {
			$query['contain'][$alias] = array(
				'fields' => array(
					$Model->{$alias}->primaryKey
				)
			);
		}
		return $query;
	}

/**
 * Helper method which adds scope to the data about to be saved.
 *
 * @param Model $Model instance of model
 * @param array $data The data to modify
 * @param string $key Key for model's field. E.g. Model.field
 * @return string $value Value for data key
 */
	protected function _addData(Model $Model, $data, $key, $value) {
		list($alias, $field) = $this->_splitKey($Model, $key);
		if (array_key_exists($alias, $data) && array_key_exists($field, $data[$alias])) {
			if ($data[$alias][$field] === false) {
				unset($data[$alias][$field]);
			}
			return $data;
		}
		$data[$alias][$field] = $value;
		return $data;
	}

/**
 * Splits key
 *
 * @param Model $Model instance of model
 * @param string $key A query key. E.g. Model.field
 * @return array The split query key. E.g. array('Model', 'field')
 */
	protected function _splitKey(Model $Model, $key) {
		list($alias, $field) = pluginSplit($key);
		if (!$alias) {
			$alias = $Model->alias;
		}
		return array($alias, $field);
	}

/**
 * Searches array recursively for a specific key
 *
 * @param string $needle Array key to find
 * @param array $haystack Array to search
 * @return boolean
 */
	protected function _array_key_exists_recursive($needle, array $haystack) {
		if (array_key_exists($needle, $haystack)) {
			return true;
		}
		foreach ($haystack as $key => $val) {
			if (is_array($val) && $this->_array_key_exists_recursive($needle, $val)) {
				return true;
			}
		}
		return false;
	}

}
