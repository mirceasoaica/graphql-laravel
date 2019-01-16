<?php

namespace Rebing\GraphQL\Support;

use GraphQL\Error\InvariantViolation;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\WrappingType;

class SelectFields
{

    /** @var array */
    private static $args = [];

    /** @var array */
    private static $select = [];

    /** @var array */
    private static $customQueries = [];

    /** @var array */
    private static $privacyValidations = [];

    /**
     * @param ResolveInfo $info
     * @param             $parentType
     * @param array       $args - arguments given with the query
     */
    public function __construct(ResolveInfo $info, $parentType, array $args)
    {
        self::$select = [];
        
        if (!is_null($info->fieldNodes[0]->selectionSet)) {
            self::$args = $args;

            self::handleSelectFields($info->getFieldSelection(5), $parentType);
//            dd(self::$select);
        }
    }

    protected static function handleSelectFields(array $requestedFields, $parentType, string $nest = '', string $prefix = '')
    {
        if ($parentType instanceof WrappingType) {
            // get the wrapped type and process that one
            return self::handleSelectFields($requestedFields, $parentType->getWrappedType(), $nest, $prefix);
        }

        foreach ($requestedFields as $key => $field) {
            // Ignore __typename, as it's a special case
            if ($key === '__typename') {
                continue;
            }

            try {
                $fieldObject = $parentType->getField($key);
            } catch (InvariantViolation $e) {
                self::addFieldToSelect($key, $prefix, $nest);
                continue;
            }

            $canSelect = self::validateField($fieldObject);
            $fieldName = self::getFieldName($fieldObject);

            $fieldNest = $nest;
            $fieldPrefix = $prefix;
            if (!$parentType instanceof PaginationType) {
                // append the current Fieldname only if type is not pagination

                // create the new prefix for current field
                $fieldPrefix = $prefix . $fieldName . '.';
                // create the new Nest path to select the fields for current field
                $fieldNest = trim($nest . '.' . $fieldName, '.');
            }

            // add all fields from eager_load relations
            self::handleEagerLoad($fieldObject, $fieldNest);

            if ($canSelect) { // field can be selected
                if (is_array($field)) {
                    if (isset($parentType->config['model'])) {
                        // there is no relation to handle.
                        if (!method_exists($parentType->config['model'], $fieldName)) {
                            // add the fields as a subselect to the current record
                            static::handleSelectFields($field, $fieldObject->getType(), $nest, $fieldPrefix);
                        } else {
                            // add fields required for the relation
                            self::handleRelation($fieldObject, $fieldName, $parentType, $nest);
                            // add fields to the new nest level
                            self::handleSelectFields($field, $fieldObject->config['type'], $fieldNest, '');
                        }
                    } else {
                        self::handleSelectFields($field, $fieldObject->config['type'], $nest, $fieldPrefix);
                    }
                } else {
                    self::addFieldToSelect($fieldObject, $prefix, $nest);
                }
            } elseif ($canSelect === null) {
                $fieldObject->resolveFn = function () {
                    return null;
                };
            } // If allowed field, but not selectable
            elseif ($canSelect === false) {
                self::addFieldToSelect($fieldObject, $prefix, $nest, false);
            }
        }
    }

    protected static function handleRelation($fieldObject, $relationName, $parentType, $nest)
    {
        $customQuery = array_get($fieldObject->config, 'query');

        $fieldName = self::getFieldName($fieldObject);
        $newNesting = trim($nest . '.' . $fieldName, '.');

        // Get the next parent type, so that 'with' queries could be made
        // Both keys for the relation are required (e.g 'id' <-> 'user_id')
        $relation = call_user_func([app($parentType->config['model']), $relationName]);

        // get the foreign keys
        $foreignKey = self::getForeignKeys($relation);
        foreach ($foreignKey as $fKey) {
            self::addFieldToSelect(self::removeTableTame($fKey), '', $nest);
        }

        // get local keys
        $localKeys = self::getLocalKeys($relation);
        foreach ($localKeys as $lKey) {
            self::addFieldToSelect(self::removeTableTame($lKey), '', $newNesting);
        }

        // add relation query
        self::addRelationCustomQuery($nest, $fieldObject);
    }

    protected static function handleEagerLoad($fieldObject, $nest)
    {
        if (isset($fieldObject->config['eager_load'])) {
            foreach ($fieldObject->config['eager_load'] as $eager) {
                // add select fields
                $foreignNest = trim($nest . '.' . $eager['relation'], '.');

                self::addFieldToSelect(data_get($eager, 'select', []), '', $foreignNest);
                // add local keys
                $parts = explode('.', $foreignNest);
                unset($parts[count($parts) - 1]);
                $newNest = implode('.', $parts);

                // add foreign keys
                self::addFieldToSelect($eager['foreignKey'], '', $newNest);
            }
        }
    }

    protected static function addRelationCustomQuery($nest, $fieldObject)
    {
        $customQuery = array_get($fieldObject->config, 'query');
        if ($customQuery) {
            $newNest = trim($nest . '.' . self::getFieldName($fieldObject) . '.customQuery', '.');
            data_set(self::$customQueries, $newNest, $customQuery);
        }
    }

    /**
     * Check the privacy status, if it's given
     *
     * @return boolean | null - true, if selectable; false, if not selectable, but allowed;
     *                          null, if not allowed
     */
    protected static function validateField($fieldObject)
    {
        $selectable = true;

        // If not a selectable field
        if (isset($fieldObject->config['selectable']) && $fieldObject->config['selectable'] === false) {
            $selectable = false;
        }

        if (isset($fieldObject->config['privacy'])) {
            $privacyClass = $fieldObject->config['privacy'];

            // If privacy given as a closure
            if (is_callable($privacyClass) && call_user_func($privacyClass, self::$args) === false) {
                $selectable = null;
            } // If Privacy class given
            elseif (is_string($privacyClass)) {
                if (array_has(self::$privacyValidations, $privacyClass)) {
                    $validated = self::$privacyValidations[$privacyClass];
                } else {
                    $validated = call_user_func([app($privacyClass), 'fire'], self::$args);
                    self::$privacyValidations[$privacyClass] = $validated;
                }

                if (!$validated) {
                    $selectable = null;
                }
            }
        }

        return $selectable;
    }

    protected static function addFieldToSelect($field, $prefix, $nest, $selectable = true)
    {
        if (is_object($field)) {
            if (isset($field->config['select'])) {
                $select = is_array($field->config['name']) ? $field->config['name'] : [$field->config['name']];
                foreach ($select as $f) {
                    self::addFieldToSelect($f, '', $nest);
                }
            }
        } elseif (is_array($field)) {
            foreach ($field as $f) {
                self::addFieldToSelect($f, $prefix, $nest);
            }

            return;
        }

        if (!$field) {
            return;
        }

        if ($selectable) {
            $fieldName = self::getFieldName($field);

            if (!$nest) {
                self::$select[$prefix . $fieldName] = true;
            } else {
                $nestValue = data_get(self::$select, $nest, []);
                $nestValue[$prefix . $fieldName] = true;

                data_set(self::$select, $nest, $nestValue);
            }
        }
    }

    protected static function getFieldName($fieldObject)
    {
        if (is_string($fieldObject)) {
            return $fieldObject;
        }

        if (isset($fieldObject->config['alias'])) {
            return $fieldObject->config['alias'];
        }

        return $fieldObject->config['name'];
    }

    private
    static function getPrimaryKeyFromParentType($parentType)
    {
        return isset($parentType->config['model']) ? app($parentType->config['model'])->getKeyName() : null;
    }

    public
    function getSelect($items = null)
    {
        if (!$items) {
            $items = self::$select;
        }

        return collect($items)
            ->filter(function ($item) {
                return !is_array($item);
            })
            ->keys()
            ->all()
            ;
    }

    public function getRelations($items = false, $customQueries = false)
    {
        $relations = [];
        if ($items === false && $customQueries === false) {
            $items = self::$select;
            $customQueries = self::$customQueries;
        }
        $that = $this;
        $args = self::$args;
        foreach ($items as $key => $item) {
            if (is_array($item)) {
                $customQuery = $customQueries[$key] ?? null;
                $relations[$key] = function ($query) use ($that, $args, $item, $customQuery) {
                    if ($customQuery && isset($customQuery['customQuery'])) {
                        $query = $customQuery['customQuery']($args, $query);
                    }
                    $query->with($that->getRelations($item, $customQuery));
                    $query->select($that->getSelect($item));
                };
            }
        }

        return $relations;
    }

    private static function removeTableTame($name)
    {
        $parts = explode('.', $name);

        return $parts[1] ?? $parts[0];
    }

    private static function getLocalKeys($relation)
    {
        if (method_exists($relation, 'getQualifiedParentKeyName')) {
            $localKeys = $relation->getQualifiedParentKeyName();
        } elseif (method_exists($relation, 'getQualifiedOwnerKeyName')) {
            $localKeys = $relation->getQualifiedOwnerKeyName();
        }

        // make the local key an array
        if (!is_array($localKeys)) {
            $localKeys = [$localKeys];
        }

        return $localKeys;
    }

    private static function getForeignKeys($relation)
    {
        $foreignKey = [];
        // Add the foreign key here, if it's a 'belongsTo'/'belongsToMany' relation
        if (method_exists($relation, 'getForeignKey')) {
            $foreignKey = $relation->getForeignKey();
        } else {
            if (method_exists($relation, 'getQualifiedForeignPivotKeyName')) {
                $foreignKey = $relation->getQualifiedForeignPivotKeyName();
            } elseif (method_exists($relation, 'getQualifiedForeignKeyName')) {
                $foreignKey = $relation->getQualifiedForeignKeyName();
            }
        }

        if (!is_array($foreignKey)) {
            $foreignKey = [$foreignKey];
        }

        return $foreignKey;
    }
}
