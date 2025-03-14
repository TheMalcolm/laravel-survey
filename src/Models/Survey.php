<?php

namespace MattDaneshvar\Survey\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use MattDaneshvar\Survey\Contracts\Entry;
use MattDaneshvar\Survey\Contracts\Question;
use MattDaneshvar\Survey\Contracts\Section;
use MattDaneshvar\Survey\Contracts\Survey as SurveyContract;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\Translatable\HasTranslations;

class Survey extends Model implements SurveyContract, HasMedia
{
    use HasTranslations, InteractsWithMedia;

    /**
     * Survey constructor.
     *
     * @param  array  $attributes
     */
    public function __construct(array $attributes = [])
    {
        if (! isset($this->table)) {
            $this->setTable(config('survey.database.tables.surveys'));
        }

        parent::__construct($attributes);
    }

    protected $translatable = ['name', 'slug', 'description'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name', 
        'slug', 
        'description', 
        'valid_from', 
        'valid_until', 
        'settings'
    ];

    /**
     * The attributes that should be casted.
     *
     * @var array
     */
    protected $casts = [
        'settings' => 'array',
        'valid_from' => 'datetime',
        'valid_until' => 'datetime',
    ];

    public static function booted()
    {
        static::creating(function ($model) {
            $model->slug = ['en' => static::generateUniqueSlug($model->name)];
        });

        static::updating(function ($model) {
            $model->slug = ['en' => static::generateUniqueSlug($model->name, $model->id)];
        });
    }

    protected static function generateUniqueSlug($name, $ignore = null)
    {
        $slug = \Str::slug($name);
        $originalSlug = $slug;
        $count = 1;

        while (static::where('slug', $slug)->when(!is_null($ignore), fn ($query) => $query->where('id', '!=', $ignore))->exists()) {
            $slug = $originalSlug . '-' . $count;
            $count++;
        }

        return $slug;
    }

    /**
     * The survey sections.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function sections()
    {
        return $this->hasMany(get_class(app()->make(Section::class)));
    }

    /**
     * The survey questions.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function questions()
    {
        return $this->hasMany(get_class(app()->make(Question::class)));
    }

    /**
     * The survey entries.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function entries()
    {
        return $this->hasMany(get_class(app()->make(Entry::class)));
    }

    /**
     * Check if survey accepts guest entries.
     *
     * @return bool
     */
    public function acceptsGuestEntries()
    {
        return $this->settings['accept-guest-entries'] ?? false;
    }

    /**
     * The maximum number of entries a participant may submit.
     *
     * @return int|null
     */
    public function limitPerParticipant()
    {
        if ($this->acceptsGuestEntries()) {
            return;
        }

        $limit = $this->settings['limit-per-participant'] ?? 1;

        return $limit !== -1 ? $limit : null;
    }

    /**
     * Survey entries by a participant.
     *
     * @param  Model  $participant
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function entriesFrom(Model $participant)
    {
        return $this->entries()->where('participant_id', $participant->id);
    }

    /**
     * Last survey entry by a participant.
     *
     * @param  Model  $participant
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function lastEntry(Model $participant = null)
    {
        return $participant === null ? null : $this->entriesFrom($participant)->first();
    }

    /**
     * Check if a participant is eligible to submit the survey.
     *
     * @param  Model|null  $model
     * @return bool
     */
    public function isEligible(Model $participant = null)
    {
        if ($participant === null) {
            return $this->acceptsGuestEntries();
        }

        if ($this->limitPerParticipant() === null) {
            return true;
        }

        return $this->limitPerParticipant() > $this->entriesFrom($participant)->count();
    }

    /**
     * Combined validation rules of the survey.
     *
     * @return mixed
     */
    public function getRulesAttribute()
    {
        return $this->questions->mapWithKeys(function ($question) {
            return [$question->key => $question->rules];
        })->all();
    }

    /**
     * Scope a query to only include active surveys.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */

    public function scopeActive($query)
    {
        return $query->where('valid_from', '<=', Carbon::now())->where('valid_until', '>=', Carbon::now());
    }
}
