<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BackgroundTask extends Model
{
    protected $table = 'background_tasks';

    public $timestamps = false;

    protected $fillable = [
        'task_type',
        'data',
        'created_at',
        'processed_at',
        'failed_at',
        'error_message',
        'attempts',
    ];

    protected $casts = [
        'data'         => 'array',
        'created_at'   => 'datetime',
        'processed_at' => 'datetime',
        'failed_at'    => 'datetime',
    ];

    // Task types produced by the Go API and consumed by the PHP batch processor.
    // Keep in sync with the Go API (iznik-server-go).
    public const TASK_EMAIL_CHARITY_SIGNUP      = 'email_charity_signup';
    public const TASK_EMAIL_CHITCHAT_REPORT     = 'email_chitchat_report';
    public const TASK_EMAIL_DONATE_EXTERNAL     = 'email_donate_external';
    public const TASK_EMAIL_FORGOT_PASSWORD     = 'email_forgot_password';
    public const TASK_EMAIL_MEMBERSHIP_APPROVED = 'email_membership_approved';
    public const TASK_EMAIL_MEMBERSHIP_REJECTED = 'email_membership_rejected';
    public const TASK_EMAIL_MERGE               = 'email_merge';
    public const TASK_EMAIL_MESSAGE_APPROVED    = 'email_message_approved';
    public const TASK_EMAIL_MESSAGE_REJECTED    = 'email_message_rejected';
    public const TASK_EMAIL_MESSAGE_REPLY       = 'email_message_reply';
    public const TASK_EMAIL_MOD_STDMSG          = 'email_mod_stdmsg';
    public const TASK_EMAIL_UNSUBSCRIBE         = 'email_unsubscribe';
    public const TASK_EMAIL_VERIFY              = 'email_verify';
    public const TASK_FREEBIE_ALERTS_ADD        = 'freebie_alerts_add';
    public const TASK_FREEBIE_ALERTS_REMOVE     = 'freebie_alerts_remove';
    public const TASK_HOUSEKEEPER_NOTIFY        = 'housekeeper_notify';
    public const TASK_MESSAGE_OUTCOME           = 'message_outcome';
    public const TASK_PUSH_NOTIFY_GROUP_MODS    = 'push_notify_group_mods';
    public const TASK_REFER_TO_SUPPORT          = 'refer_to_support';
    public const TASK_REMAP_POSTCODES           = 'remap_postcodes';
    public const TASK_USER_FORGET               = 'user_forget';
}
