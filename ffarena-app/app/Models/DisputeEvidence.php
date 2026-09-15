<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An immutable piece of evidence attached to a dispute.
 *
 * File evidence is stored on the private `local` disk (never publicly
 * served) and streamed only through an authorized controller. `text`
 * evidence has no file — the explanation lives in `description`.
 *
 * All fields are server-controlled — nothing is mass-assignable.
 */
class DisputeEvidence extends Model
{
    use HasFactory;

    public const TYPE_IMAGE = 'image';
    public const TYPE_VIDEO = 'video';
    public const TYPE_DOCUMENT = 'document';
    public const TYPE_TEXT = 'text';

    public const TYPES = [
        self::TYPE_IMAGE,
        self::TYPE_VIDEO,
        self::TYPE_DOCUMENT,
        self::TYPE_TEXT,
    ];

    /**
     * File types that require an uploaded file (everything except text).
     */
    public const FILE_TYPES = [
        self::TYPE_IMAGE,
        self::TYPE_VIDEO,
        self::TYPE_DOCUMENT,
    ];

    /**
     * Whitelisted extensions per evidence type. Used to generate the stored
     * filename and to re-verify uploads server-side.
     */
    public const ALLOWED_EXTENSIONS = [
        self::TYPE_IMAGE => ['jpg', 'jpeg', 'png', 'webp', 'gif'],
        self::TYPE_VIDEO => ['mp4', 'webm', 'mov'],
        self::TYPE_DOCUMENT => ['pdf'],
    ];

    /**
     * Whitelisted MIME types per evidence type (server-side re-check).
     */
    public const ALLOWED_MIME_TYPES = [
        self::TYPE_IMAGE => ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
        self::TYPE_VIDEO => ['video/mp4', 'video/webm', 'video/quicktime'],
        self::TYPE_DOCUMENT => ['application/pdf'],
    ];

    /**
     * Maximum evidence file size in kilobytes.
     */
    public const MAX_KB = 10240;

    protected $fillable = [];

    protected $casts = [
        'size' => 'integer',
    ];

    public function dispute()
    {
        return $this->belongsTo(Dispute::class);
    }

    public function submitter()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function typeLabel(): string
    {
        return ucfirst($this->type);
    }
}
