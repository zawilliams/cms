<?php

namespace Statamic\Forms;

use Carbon\Carbon;
use Statamic\Contracts\Forms\Submission as SubmissionContract;
use Statamic\Data\ContainsData;
use Statamic\Events\Data\SubmissionDeleted;
use Statamic\Events\Data\SubmissionSaved;
use Statamic\Facades\File;
use Statamic\Facades\YAML;
use Statamic\Support\Traits\FluentlyGetsAndSets;

class Submission implements SubmissionContract
{
    use ContainsData, FluentlyGetsAndSets;

    /**
     * @var string
     */
    private $id;

    /**
     * @var Form
     */
    public $form;

    /**
     * Get or set the ID.
     *
     * @param mixed|null
     * @return mixed
     */
    public function id($id = null)
    {
        return $this->fluentlyGetOrSet('id')
            ->getter(function ($id) {
                return $id ?: microtime(true);
            })
            ->args(func_get_args());
    }

    /**
     * Get or set the form.
     *
     * @param Form|null $form
     * @return Form
     */
    public function form($form = null)
    {
        return $this->fluentlyGetOrSet('form')->args(func_get_args());
    }

    /**
     * Get the form fields.
     *
     * @return array
     */
    public function fields()
    {
        return $this->form()->fields()->map->toArray();
    }

    /**
     * Get or set the columns.
     *
     * @return array
     */
    public function columns()
    {
        return $this->form()->blueprint()->columns();
    }

    /**
     * Get the date when this was submitted.
     *
     * @return Carbon
     */
    public function date()
    {
        return Carbon::createFromTimestamp($this->id());
    }

    /**
     * Get the date, formatted by what's specified in the form config.
     *
     * @return string
     */
    public function formattedDate()
    {
        return $this->date()->format(
            $this->form()->dateFormat()
        );
    }

    /**
     * Get or set the data.
     *
     * @param array|null $data
     * @return array
     */
    public function data($data = null)
    {
        if (func_num_args() === 0) {
            return $this->data;
        }

        $data = collect($data)->intersectByKeys($this->fields())->all();

        $this->data = $data;

        return $this;
    }

    /**
     * Upload files.
     */
    public function uploadFiles()
    {
        $request = request();

        collect($this->fields())->filter(function ($field) {
            // Only deal with uploadable fields
            return in_array(array_get($field, 'type'), ['file', 'files', 'asset', 'assets']);
        })->map(function ($config, $field) {
            // Map into a nicer data schema to work with
            return compact('field', 'config');
        })->reject(function ($arr) use ($request) {
            // Remove if no file was uploaded
            return ! $request->hasFile($arr['field']);
        })->map(function ($arr, $field) use ($request) {
            // Add the uploaded files to our data array
            $files = collect(array_filter((array) $request->file($field)));
            $arr['files'] = $files;

            return $arr;
        })->each(function ($arr) {
            // A plural type uses the singular version. assets => asset, etc.
            $type = rtrim(array_get($arr, 'config.type'), 's');

            // Upload the files
            $class = 'Statamic\Forms\Uploaders\\'.ucfirst($type).'Uploader';
            $uploader = new $class(array_get($arr, 'config'), array_get($arr, 'files'));
            $data = $uploader->upload();

            // Add the resulting paths to our submission
            array_set($this->data, $arr['field'], $data);
        });

        return $this;
    }

    /**
     * Whether the submissin has the given key.
     *
     * @return bool
     */
    public function has($field)
    {
        return array_has($this->data(), $field);
    }

    /**
     * Get a value of a field.
     *
     * @param  string $key
     * @return mixed
     */
    public function get($field)
    {
        return array_get($this->data(), $field);
    }

    /**
     * Set a value of a field.
     *
     * @param string $field
     * @param mixed  $value
     * @return void
     */
    public function set($field, $value)
    {
        array_set($this->data, $field, $value);
    }

    /**
     * Save the submission.
     */
    public function save()
    {
        File::put($this->getPath(), YAML::dump($this->data()));

        SubmissionSaved::dispatch($this);
    }

    /**
     * Delete this submission.
     */
    public function delete()
    {
        File::delete($this->getPath());

        SubmissionDeleted::dispatch($this);
    }

    /**
     * Get the path to the file.
     *
     * @return string
     */
    public function getPath()
    {
        return config('statamic.forms.submissions').'/'.$this->form()->handle().'/'.$this->id().'.yaml';
    }

    /**
     * Convert to an array.
     *
     * @return array
     */
    public function toArray()
    {
        $data = $this->data();

        return $this->form()->fields()->keys()->flip()
            ->reject(function ($field, $key) {
                return in_array($key, ['id', 'date']);
            })
            ->map(function ($field, $key) use ($data) {
                return $data[$key] ?? null;
            })
            ->merge([
                'id' => $this->id(),
                'date' => $this->date(),
            ])
            ->all();
    }
}
