<?php
declare(strict_types=1);
namespace Biblio\Core\Assessments;
enum ModerationStatus: string { case Visible = 'visible'; case Hidden = 'hidden'; case Removed = 'removed'; }
