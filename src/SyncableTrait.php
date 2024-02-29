<?php namespace Jwohlfert23\LaravelSyncRelations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\ValidationRuleParser;
use Models\Comment;

/** @mixin Model */
trait SyncableTrait
{
    protected $syncable = [];

    public function getSyncable()
    {
        return $this->syncable;
    }

    protected function getSyncValidationRules()
    {
        return [];
    }

    protected function getSyncValidationMessages()
    {
        return [];
    }

    public static function syncing($callback)
    {
        static::registerModelEvent('syncing', $callback);
    }

    public static function synced($callback)
    {
        static::registerModelEvent('synced', $callback);
    }

    protected function getOrderAttributeName()
    {
        return null;
    }

    public function beforeSync(array $data)
    {
        return $data;
    }

    public function afterSync($data)
    {
        return $this;
    }

    public function finishSave(array $options)
    {
        $this->fireModelEvent('saved', false);

        if ($this->isDirty() && ($options['touch'] ?? true)) {
            $this->touchOwners();
        }

        if (empty($options['skip_sync_original'])) {
            $this->syncOriginal();
        }
    }

    public function syncRelationshipsFromTree(array $relationships, $data)
    {
        if (!is_iterable($relationships))
            return;

        foreach ($relationships as $relationship => $children) {
            $snake = Str::snake($relationship);
            $relationshipModel = $this->{$relationship}();

            if (Arr::has($data, $snake)) {
                $new = Arr::get($data, $snake);

                $relatedModel = $relationshipModel->getRelated();
                $primaryKey = $relatedModel->getKeyName();

                if (SyncableHelpers::isRelationOneToMany($relationshipModel)) {
                    /** @var $relationshipModel HasOneOrMany */

                    // Handle hasOne relationships
                    if (SyncableHelpers::isRelationSingle($relationshipModel)) {
                        $new = [$new];
                    }
                    $new = collect($new);

                    $toRemove = $relationshipModel->pluck($primaryKey)->filter(function ($id) use ($new, $primaryKey) {
                        return !$new->pluck($primaryKey)->contains($id);
                    });

                    foreach ($new as $index => $item) {
                        $item = $relatedModel->beforeSync($item);

                        /* if ($orderProp = $relatedModel->getOrderAttributeName()) {
                            Arr::set($item, $orderProp, count($new) + 1 - $index);
                        } */

                        $related = SyncableHelpers::relatedExists($relationshipModel, $item);
                        if (!$related) {
                            $related = $relationshipModel->make();
                        }

                        $related->fill(Arr::except($item, [$primaryKey]))
                            ->syncBelongsTo($item, is_array($children) ? array_keys($children) : [])
                            ->save();

                        $related->afterSync($item);

                        if (is_array($children)) {
                            $related->syncRelationshipsFromTree($children, $item);
                        }
                    }

                    // Don't use quick delete, otherwise it won't trigger observers
                    $toRemove->each(function ($id) use ($relationshipModel) {
                        if ($model = $relationshipModel->getRelated()->newModelQuery()->find($id)) {
                            $model->delete();
                        }
                    });
                } else if (is_a($relationshipModel, BelongsToMany::class)) {
                    /** @var $relationshipModel BelongsToMany */

                    $ids = collect($new)->whereNotNull($primaryKey)->mapWithKeys(function ($item) use ($primaryKey, $relationshipModel) {
                        $key = Arr::get($item, $primaryKey);
                        // Get pivot data without the keys in the pivot table (just the extra pivot data)
                        $pivot = Arr::except(Arr::get($item, 'pivot', []), [
                            $relationshipModel->getForeignPivotKeyName(),
                            $relationshipModel->getRelatedPivotKeyName(),
                            'created_at',
                            'updated_at'
                        ]);
                        return [$key => $pivot];
                    })->all();
                    $relationshipModel->sync($ids);
                }

                if ($this->relationLoaded($relationship)) {
                    $this->unsetRelation($relationship);
                }
            }
        }
    }

    public function getSyncableRules($relationships, $data)
    {
        $rules = $this->getSyncValidationRules();

        if (is_iterable($relationships)) {
            foreach ($relationships as $relationship => $children) {
                $relationshipModel = $this->{$relationship}();
                $snake = Str::snake($relationship);
                $related = $relationshipModel->getRelated();

                if (Arr::has($data, $snake)) {
                    $item = $data[$snake];
                    if (SyncableHelpers::isRelationOneToMany($relationshipModel)) {
                        $key = SyncableHelpers::isRelationMany($relationshipModel) ? ($snake . '.*') : $snake;
                        $rules[$key] = $related->getSyncableRules($children, is_array($item) ? Arr::first($item) : $item);
                    } else if (get_class($relationshipModel) === BelongsTo::class) {
                        $pk = $related->getKeyName();
                        $rules["$snake.$pk"] = Rule::exists($related->getTable(), $pk);
                    }
                }
            }
        }


        return SyncableHelpers::dot($rules);
    }

    public function getDataWithExists($relationships, $data, $exists = null)
    {
        $data['_exists'] = is_null($exists) ? !empty($data[$this->getKeyName()]) : $exists;
        $data['_pk'] = is_null($exists) ? Arr::get($data, $this->getKeyName()) : $this->getKey();
        $data['_pk_name'] = $this->getKeyName();

        if (is_iterable($relationships)) {
            foreach ($relationships as $relationship => $children) {
                $relationshipModel = $this->{$relationship}();
                $snake = Str::snake($relationship);
                $related = $relationshipModel->getRelated();

                if ($item = Arr::get($data, $snake)) {
                    if (SyncableHelpers::isRelationSingle($relationshipModel)) {
                        $data[$snake] = $related->getDataWithExists($children, $item);
                    } else {
                        $data[$snake] = array_map(function ($item) use ($related, $children) {
                            return $related->getDataWithExists($children, $item);
                        }, $data[$snake]);
                    }
                }
            }
        }
        return $data;
    }

    public function getCompleteRules($relationships, $data)
    {
        $flat = $this->getSyncableRules($relationships, $data);
        return SyncableHelpers::makeRulesRelative($flat);
    }

    /**
     * @param $relationships
     * @param $data
     *
     * @throws ValidationException
     */
    protected function validateFromTree($relationships, $data)
    {
        $rules = $this->getCompleteRules($relationships, $data);
        $data = $this->getDataWithExists($relationships, $data, $this->exists);
        $validator = Validator::make($data, $rules, $this->getSyncValidationMessages());
        $validator->validate();
    }

    public function syncRelationships($dotRelationship, $data)
    {
        $tree = SyncableHelpers::parseRelationships($dotRelationship);
        $this->syncRelationshipsFromTree($tree, $data);
        return $this;
    }

    public function syncBelongsToFromDot($dotRelationship, $data)
    {
        $tree = SyncableHelpers::parseRelationships($dotRelationship);
        return $this->syncBelongsTo($data, array_keys($tree));
    }

    public function validateForSync($dotRelationship, $data)
    {
        $tree = SyncableHelpers::parseRelationships($dotRelationship);
        $this->validateFromTree($tree, $data);
        return $this;
    }

    /**
     * Will associate all belongsTo relationships that have been passed
     * Will not save
     * Not recursive
     *
     * @param $data
     * @param null $toSync
     */
    public function syncBelongsTo($data, $relationships = [])
    {
        foreach ($relationships as $relationship) {
            $snake = Str::snake($relationship);
            $relationshipModel = $this->{$relationship}();

            if (is_a($relationshipModel, BelongsTo::class) && Arr::has($data, $snake)) {
                /** @var $relationshipModel BelongsTo */
                if ($parent = SyncableHelpers::relatedExists($relationshipModel, $data[$snake])) {
                    $relationshipModel->associate($parent);
                } else {
                    $relationshipModel->dissociate();
                }
            }
        }
        return $this;
    }

    /**
     * @param $data
     * @param array $toSync
     * @return $this
     *
     * @throws ValidationException
     */
    public function saveAndSync($data, array $toSync = null)
    {
        // Default to syncable property on model
        $toSync = is_null($toSync) ? $this->getSyncable() : $toSync;

        $this->validateForSync($toSync, $data);

        if ($this->fireModelEvent('syncing') === false) {
            return false;
        }

        $data = $this->beforeSync($data);

        $this->fill($data)
            ->syncBelongsToFromDot($toSync, $data)
            ->save(['skip_sync_original' => true]);

        $this->syncRelationships($toSync, $data);

        $this->fireModelEvent('synced');
        $this->syncOriginal();

        return $this->afterSync($data);
    }

    protected function initializeSyncableTrait()
    {
        $this->addObservableEvents(['syncing', 'synced']);
    }
}
