<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class ProfileMedia extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'profile_media';

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
        'profile_id',
        'user_id',
        'type',
        'disk',
        'path',
        'mime_type',
        'size',
        'width',
        'height',
        'alt_text',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }

    /**
     * Get the profile that owns the media.
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    /**
     * Get the user that owns the media.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Resolve public CDN / storage URL dynamically.
     */
    public function getUrlAttribute(): string
    {
        $customCdnUrl = config('filesystems.disks.' . $this->disk . '.url');
        if (!empty($customCdnUrl)) {
            return rtrim($customCdnUrl, '/') . '/' . ltrim($this->path, '/');
        }

        try {
            $url = Storage::disk($this->disk)->url($this->path);
            if (str_contains($url, 'localhost') || str_contains($url, '127.0.0.1')) {
                $appUrl = env('APP_URL');
                if ($appUrl && !str_contains($appUrl, 'localhost') && !str_contains($appUrl, '127.0.0.1')) {
                    return rtrim($appUrl, '/') . '/storage/' . ltrim($this->path, '/');
                }
                return 'https://lightslategray-snake-169437.hostingersite.com/storage/' . ltrim($this->path, '/');
            }
            return $url;
        } catch (\Throwable) {
            return 'https://lightslategray-snake-169437.hostingersite.com/storage/' . ltrim($this->path, '/');
        }
    }
}
