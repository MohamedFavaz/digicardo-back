<?php

namespace App\Enums;

enum AbuseReportReason: string
{
    case Spam = 'spam';
    case Phishing = 'phishing';
    case Impersonation = 'impersonation';
    case MaliciousContent = 'malicious_content';
    case Copyright = 'copyright';
    case Harassment = 'harassment';
    case InappropriateContent = 'inappropriate_content';
    case Other = 'other';
}
