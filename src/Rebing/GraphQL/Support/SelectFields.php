<?php

namespace Rebing\GraphQL\Support;

use Closure;
use GraphQL\Error\InvariantViolation;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\ListOfType;
use GraphQL\Type\Definition\NonNull;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\UnionType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class SelectFields
{

    /** @var array */
    private static $args = [];
    /** @var array */
    private $select = [];
    /** @var array */
    private $relations = [];
    /** @var array */
    private static $privacyValidations = [];

    const FOREIGN_KEY = 'foreignKey';

    /**
     * @param ResolveInfo $info
     * @param             $parentType
     * @param array       $args - arguments given with the query
     */
    public function __construct(ResolveInfo $info, $parentType, array $args)
    {
        if (!is_null($info->fieldNodes[0]->selectionSet)) {
            self::$args = $args;

            $fields = self::getSelectableFieldsAndRelations($info->getFieldSelection(5), $parentType);

            $this->select = $fields[0];
            $this->relations = $fields[1];
        }
    }

    /**
     * Retrieve the fields (top level) and relations that
     * will be selected with the query
     *
     * @return array | Closure - if first recursion, return an array,
     *      where the first key is 'select' array and second is 'with' array.
     *      On other recursions return a closure that will be used in with
     */
    public static function getSelectableFieldsAndRelations(array $requestedFields, $parentType, $customQuery = null, $topLevel = true)
    {
        $select = [];
        $with = [];

        if (is_a($parentType, ListOfType::class)) {
            $parentType = $parentType->getWrappedType();
        }
        self::getTableNameFromParentType($parentType);
        $primaryKey = self::getPrimaryKeyFromParentType($parentType);

        self::handleFields($requestedFields, $parentType, $select, $with);
        
        // If a primary key is given, but not in the selects, add it
        if (!is_null($primaryKey)) {

            if (!in_array($primaryKey, $select)) {
                $select[] = $primaryKey;
            }
        }
        
        if ($topLevel) {
            return [$select, $with];
        } else {
            return function ($query) use ($with, $select, $customQuery) {
                if ($customQuery) {
                    $query = $customQuery(self::$args, $query);
                }

                $query->select($select);
                $query->with($with);
            };
        }
    }

    protected static function handleFields(array $requestedFields, $parentType, array &$select, array &$with, $prefix = '')
    {
        if ($parentType instanceof NonNull || $parentType instanceof ListOfType) {
            return self::handleFields($requestedFields, $parentType->getWrappedType(), $select, $with, $prefix);
        }

        foreach ($requestedFields as $key => $field) {
            // Ignore __typename, as it's a special case
            if ($key === '__typename') {
                continue;
            }

            // Always select foreign key
            if ($field === self::FOREIGN_KEY) {
                self::addFieldToSelect($key, $select, false);
                continue;
            }

            try {
                $fieldObject = $parentType->getField($key);
            } catch (InvariantViolation $e) {
                self::addFieldToSelect($prefix . $key, $select, false);
            }
            
            $canSelect = self::validateField($fieldObject);
            if ($canSelect) { // field can be selected
                // Pagination
                if (is_a($parentType, PaginationType::class)) {
                    self::handleFields($field, $fieldObject->config['type']->getWrappedType(), $select, $with);
                    continue; // nothing to do here
                }

                if (is_array($field)) {
                    if (isset($parentType->config['model'])) {
                        $customQuery = array_get($fieldObject->config, 'query');

                        if (isset($fieldObject->config['alias'])) {
                            self::addFieldToSelect($prefix . $fieldObject->config['alias'], $select, false);
                        }

                        if (isset($fieldObject->config['eager_load'])) {
                            $newParentType = $parentType->getField($key)->config['type'];
                            foreach ($fieldObject->config['eager_load'] as $load) {
                                if (isset($load['referenceKey'])) {
                                    $field[$load['referenceKey']] = true;
                                }

                                $with[$load['relation']] = self::getSelectableFieldsAndRelations($field, $newParentType, $customQuery, false);
                                self::addFieldToSelect($prefix . $load['foreignKey'], $select, false);
                            }
                        }

                        if (!method_exists($parentType->config['model'], $key)) {
                            $name = $fieldObject->config['name'];
                            static::handleFields($field, $fieldObject->getType(), $select, $with, $prefix . $name . '.');
                            continue;
                        }

                        // Get the next parent type, so that 'with' queries could be made
                        // Both keys for the relation are required (e.g 'id' <-> 'user_id')
                        $relation = call_user_func([app($parentType->config['model']), $key]);
                        // Add the foreign key here, if it's a 'belongsTo'/'belongsToMany' relation
                        if (method_exists($relation, 'getForeignKey')) {
                            $foreignKey = $relation->getForeignKey();
                        } else {
                            if (method_exists($relation, 'getQualifiedForeignPivotKeyName')) {
                                $foreignKey = $relation->getQualifiedForeignPivotKeyName();
                            } else {
                                $foreignKey = $relation->getQualifiedForeignKeyName();
                            }
                        }


                        if (is_a($relation, BelongsTo::class) || is_a($relation, MorphTo::class)) {
                            if (!in_array($foreignKey, $select)) {
                                $select[] = $foreignKey;
                            }
                        } // If 'HasMany', then add it in the 'with'
                        elseif (is_a($relation, HasMany::class) || is_a($relation, MorphMany::class) || is_a($relation, HasOne::class)) {
                            $parts = explode('.', $foreignKey);
                            $foreignKey = $parts[1] ?? $parts[0];
                            if (!array_key_exists($foreignKey, $field)) {
                                $field[$foreignKey] = self::FOREIGN_KEY;
                            }
                        }
                           
                        // New parent type, which is the relation
                        $newParentType = $parentType->getField($key)->config['type'];
                           
                        self::addAlwaysFields($fieldObject, $field, true);
                           
                        $with[$key] = self::getSelectableFieldsAndRelations($field, $newParentType, $customQuery, false);
                    } else {
                        self::handleFields($field, $fieldObject->config['type'], $select, $with);
                    }

                } else {
                    $key = isset($fieldObject->config['alias'])
                        ? $fieldObject->config['alias']
                        : $key;

                    self::addFieldToSelect($prefix . $key, $select, false);

                    self::addAlwaysFields($fieldObject, $select);
                }

            } elseif ($canSelect === null) {
                $fieldObject->resolveFn = function () {
                    return null;
                };
            } // If allowed field, but not selectable
            elseif ($canSelect === false) {
                self::addAlwaysFields($fieldObject, $select);
            }
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

    /**
     * Add selects that are given by the 'always' attribute
     */
    protected static function addAlwaysFields($fieldObject, array &$select, $forRelation = false)
    {
        if (isset($fieldObject->config['always'])) {
            $always = $fieldObject->config['always'];

            if (is_string($always)) {
                $always = explode(',', $always);
            }

            // Get as 'field' => true
            foreach ($always as $field) {
                self::addFieldToSelect($field, $select, $forRelation);
            }
        }
    }

    protected static function addFieldToSelect($field, &$select, $forRelation)
    {
        if ($forRelation && !array_key_exists($field, $select)) {
            $select[$field] = true;
        } elseif (!$forRelation && !in_array($field, $select)) {
            $select[] = $field;
        }
    }

    private static function getPrimaryKeyFromParentType($parentType)
    {
        return isset($parentType->config['model']) ? app($parentType->config['model'])->getKeyName() : null;
    }

    private static function getTableNameFromParentType($parentType)
    {
        return isset($parentType->config['model']) ? app($parentType->config['model'])->getTable() : null;
    }

    public function getSelect()
    {
        return $this->select;
    }

    public function getRelations()
    {
        return $this->relations;
    }

}
