<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Profile extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

    /**
     * Indicates if the IDs are auto-incrementing.
     */
    public $incrementing = false;

    /**
     * The data type of the primary key ID.
     */
    protected $keyType = 'string';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'username',
        'display_name',
        'bio',
        'avatar_url',
        'cover_url',
        'template_id',
        'category',
        'card_title',
        'status',
        'is_primary',
        'published_at',
        'published_url',
        'theme_tokens',
        'is_public',
        'seo_title',
        'seo_description',
        'seo_keywords',
        'og_title',
        'og_description',
        'og_image_media_id',
        'indexable',
        'version',
        'moderation_status',
        'moderation_reason',
        'moderation_notes',
        'moderated_at',
        'moderated_by',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'theme_tokens' => 'array',
            'is_public' => 'boolean',
            'seo_keywords' => 'array',
            'indexable' => 'boolean',
            'version' => 'integer',
            'category' => \App\Enums\CardCategory::class,
            'status' => \App\Enums\CardStatus::class,
            'is_primary' => 'boolean',
            'published_at' => 'datetime',
            'moderation_status' => \App\Enums\ProfileModerationStatus::class,
            'moderated_at' => 'datetime',
        ];
    }

    /**
     * Scope a query to only include primary cards.
     */
    public function scopePrimary($query)
    {
        return $query->where('is_primary', true);
    }

    /**
     * Scope a query to only include published cards.
     */
    public function scopePublished($query)
    {
        return $query->where('status', \App\Enums\CardStatus::Published);
    }

    /**
     * Scope a query to filter by category.
     */
    public function scopeCategory($query, $category)
    {
        if ($category instanceof \App\Enums\CardCategory) {
            return $query->where('category', $category);
        }
        return $query->where('category', $category);
    }

    /**
     * Get the user that owns the profile.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the content blocks associated with the profile.
     */
    public function blocks(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ProfileBlock::class)->orderBy('sort_order', 'asc');
    }

    /**
     * Get all media items uploaded for the profile.
     */
    public function media(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ProfileMedia::class);
    }

    /**
     * Get all contact form submissions for the profile.
     */
    public function contactSubmissions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ContactSubmission::class)->orderBy('created_at', 'desc');
    }

    /**
     * Get all custom domains attached to the profile.
     */
    public function domains(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ProfileDomain::class);
    }

    /**
     * Get the primary active custom domain for the profile.
     */
    public function primaryDomain(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(ProfileDomain::class)->where('is_primary', true)->where('status', 'active');
    }

    /**
     * Get the Open Graph preview image media associated with the profile.
     */
    public function ogImageMedia(): BelongsTo
    {
        return $this->belongsTo(ProfileMedia::class, 'og_image_media_id');
    }

    /**
     * Get abuse reports filed against this profile.
     */
    public function abuseReports(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AbuseReport::class);
    }
}
