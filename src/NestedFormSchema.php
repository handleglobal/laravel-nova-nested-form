<?php

namespace Handleglobal\NestedForm;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use JsonSerializable;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\File;
use Laravel\Nova\Fields\MorphTo;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Panel;
use ReflectionMethod;

class NestedFormSchema implements JsonSerializable
{
    /**
     * Parent form instance.
     *
     * @var NestedForm
     */
    protected $parentForm;

    /**
     * Current model instance.
     *
     * @var Model
     */
    protected $model;

    /**
     * Current index.
     *
     * @var int|string
     */
    protected $index;

    /**
     * List of fields.
     */
    public $fields;

    /**
     * Name of the fields' fitler method.
     *
     * @var string
     */
    protected static $filterMethod = 'creationFields';

    /**
     * Current request.
     */
    protected NovaRequest $request;

    /**
     * Create a new NestedFormSchema instance.
     */
    public function __construct(Model $model, $index, NestedForm $parentForm)
    {
        $this->model = $model;
        $this->index = $index;
        $this->parentForm = $parentForm;
        $this->request = app(NovaRequest::class);
        $this->fields = $this->fields();
    }

    /**
     * Get the fields for the current schema.
     */
    protected function fields()
    {

        $this->request->route()->setParameter('resource', $this->parentForm->resourceName);

        $fields = $this->filterFields()->map(function ($field) {
            if ($field instanceof NestedForm) {
                $field->attribute = $this->attribute($field->attribute);
            } else {
                $field->withMeta([
                    'attribute' => $this->attribute($field->attribute),
                    'originalAttribute' => $field->attribute
                ]);
            }

            $field->resolve($this->model);
            if ($field instanceof BelongsTo) {
                $this->setComponent($field);

                return with(app(NovaRequest::class), function ($request) use ($field) {
                    $viewable = ! is_null($field->viewable) ? $field->viewable : $field->resourceClass::authorizedToViewAny($request);

                    $data =  array_merge([
                        'belongsToId' => $field->belongsToId,
                        'relationshipType' => $field->relationshipType(),
                        'belongsToRelationship' => $field->belongsToRelationship,
                        'debounce' => $field->debounce,
                        'displaysWithTrashed' => $field->displaysWithTrashed,
                        'label' => $field->resourceClass::label(),
                        'resourceName' => $field->resourceName,
                        'reverse' => $field->isReverseRelation($request),
                        'searchable' => $field->isSearchable($request),
                        'withSubtitles' => $field->withSubtitles,
                        'showCreateRelationButton' => $field->createRelationShouldBeShown($request),
                        'singularLabel' => $field->singularLabel,
                        'viewable' => $viewable,
                        'uniqueKey' => sprintf(
                            '%s-%s-%s',
                            $field->attribute,
                            Str::slug($field->panel ?? 'default'),
                            $field->component()
                        ),
                        'attribute' => $this->attribute($field->attribute),
                        'component' => $field->component(),
                        'dependsOn' => $this->getDependentsAttributes($request), 'helpText' => $field->getHelpText(),
                        'indexName' => $field->name, 'name' => $field->name, 'nullable' => $field->nullable,
                        'panel' => $field->panel, 'prefixComponent' => true, 'readonly' => $field->isReadonly($request),
                        'required' => $field->isRequired($request), 'sortable' => $field->sortable,
//                        'sortableUriKey' => $field->sortableUriKey(),
                        'stacked' => $field->stacked,
                        'textAlign' => $field->textAlign,
                        'validationKey' => $field->validationKey(),
                        'usesCustomizedDisplay' => $field->usesCustomizedDisplay,
                        'value' => $field->value,
                        'visible' => $field->visible,
                        'wrapping' => $field->wrapping,
                        'displayedAs' => $field->displayedAs,
                    ]);
                    return $data;
                });
            }

            $data = $this->setComponent($field)->jsonSerialize();

            return $data;
        })->values();


        $this->request->route()->setParameter('resource', $this->parentForm->viaResource);

        return $fields;
    }

    protected function getDependentsAttributes(NovaRequest $request)
    {
        return collect($this->fieldDependencies ?? [])->map(function ($dependsOn) {
                return collect($dependsOn['attributes'])->mapWithKeys(function ($attribute) use ($dependsOn) {
                    return [$attribute => optional(Arr::get($dependsOn, 'formData'))->get($attribute)];
                })->all();
            })->first() ?? null;
    }

    /**
     * Set the custom component if need be.
     */
    protected function setComponent(Field $field)
    {
        if ($field instanceof BelongsTo) {
            $field->component = 'nested-form-belongs-to-field';
        } else if ($field instanceof File) {
            $field->component = 'nested-form-file-field';
        } else if ($field instanceof MorphTo) {
            $field->component = 'nested-form-morph-to-field';
        }

        return $field;
    }

    /*
     * Turn an attribute into a nested attribute.
     */
    protected function attribute(string $attribute = null)
    {
        return $this->parentForm->attribute . '[' . $this->index .  ']' . ($attribute ? '[' . $attribute . ']' : '');
    }

    /**
     * Get the current heading.
     */
    protected function heading()
    {
        $heading = isset($this->parentForm->heading) ? $this->parentForm->heading : $this->defaultHeading();

        return str_replace($this->parentForm::wrapIndex(), $this->index, $heading);
    }

    /**
     * Default heading.
     */
    protected function defaultHeading()
    {
        return $this->parentForm::wrapIndex() . $this->parentForm->separator . ' ' . $this->parentForm->singularLabel;
    }

    /**
     * Return the method reflection to filter
     * the fields.
     */
    protected function filterFields()
    {
        $method = new ReflectionMethod($this->parentForm->resourceClass, static::$filterMethod);

        $method->setAccessible(true);

        return $method->invoke($this->resourceInstance(), $this->request, $this->resourceInstance()->availableFields($this->request)
            ->reject(function ($field) {
                return $this->parentForm->isRelatedField($field);
            })->map(function ($field) {
                if ($field instanceof Panel) {
                    return collect($field->data)->map(function ($field) {
                        $field->panel = null;
                        return $field;
                    })->values();
                }

                return $field;
            })->flatten());
    }

    /**
     * Return an instance of the nested form resource class.
     */
    protected function resourceInstance()
    {
        return new $this->parentForm->resourceClass($this->model);
    }

    /**
     * Create a new NestedFormSchema instance.
     */
    public static function make(...$arguments)
    {
        return new static(...$arguments);
    }

    /**
     * Prepare the field for JSON serialization.
     *
     * @return array
     */
    public function jsonSerialize(): array
    {
        return [
            'fields' => $this->fields,
            'heading' => $this->heading(),
            'opened' => $this->parentForm->opened,
            'attribute' => $this->attribute()
        ];
    }
}
