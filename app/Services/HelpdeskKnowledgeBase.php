<?php

namespace App\Services;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;

class HelpdeskKnowledgeBase
{
    /**
     * Static index of Freshdesk solution articles with coarse keywords.
     * Extendable later with embedding search or external search APIs.
     *
     * @return array<int, array{title:string,url:string,keywords:array<int,string>}>
     */
    public function articles(): array
    {
        return [
            ['title' => 'Adding Another Module', 'url' => 'https://parentpulse.freshdesk.com/support/solutions/articles/72000641588-adding-another-module', 'keywords' => ['module', 'add module', 'another module'] ],
            ['title' => 'Benchmark Scores', 'url' => 'https://parentpulse.freshdesk.com/support/solutions/articles/72000641587-benchmark-scores', 'keywords' => ['benchmark', 'scores', 'benchmarks'] ],
            ['title' => 'Finding links for review sites', 'url' => 'https://parentpulse.freshdesk.com/support/solutions/articles/72000641586-finding-links-for-review-sites', 'keywords' => ['review links', 'review sites', 'links for review'] ],
            ['title' => 'How do survey reminders work?', 'url' => 'https://parentpulse.freshdesk.com/support/solutions/articles/72000641585-how-do-survey-reminders-work-', 'keywords' => ['survey reminders', 'reminders', 'emails', 'notifications'] ],
            ['title' => 'Pulse Surveys vs. Custom Surveys', 'url' => 'https://parentpulse.freshdesk.com/support/solutions/articles/72000641584-pulse-surveys-vs-custom-surveys', 'keywords' => ['pulse surveys', 'custom surveys', 'difference', 'compare'] ],

            ['title' => 'STEP 1: Invite your colleagues', 'url' => 'https://parentpulse.freshdesk.com/support/solutions/articles/72000641602-step-1-invite-your-colleagues', 'keywords' => ['invite', 'colleagues', 'users', 'staff'] ],
            ['title' => 'STEP 2: Import your parents, students and/or employees', 'url' => 'https://parentpulse.freshdesk.com/support/solutions/articles/72000641601-step-2-import-your-parents-students-and-or-employees', 'keywords' => ['import', 'parents', 'students', 'employees', 'csv'] ],
            ['title' => 'STEP 3: Build your survey(s)', 'url' => 'https://parentpulse.freshdesk.com/support/solutions/articles/72000641600-step-3-build-your-survey-s-', 'keywords' => ['build survey', 'create survey', 'design survey'] ],
            ['title' => 'Building the Survey', 'url' => 'https://parentpulse.freshdesk.com/support/solutions/articles/72000641599-building-the-survey', 'keywords' => ['survey builder', 'building', 'questions'] ],
            ['title' => 'STEP 4: Fine-tune your settings', 'url' => 'https://parentpulse.freshdesk.com/support/solutions/articles/72000641598-step-4-fine-tune-your-settings', 'keywords' => ['settings', 'fine-tune', 'configuration'] ],

            ['title' => 'Creating Demographic Restrictions', 'url' => 'https://parentpulse.freshdesk.com/support/solutions/articles/72000641597-creating-demographic-restrictions', 'keywords' => ['demographic', 'restrictions', 'segments'] ],
            ['title' => 'How to Add Admin', 'url' => 'https://parentpulse.freshdesk.com/support/solutions/articles/72000641596-how-to-add-admin', 'keywords' => ['add admin', 'new admin', 'administrator'] ],
            ['title' => 'How to Change Primary Admin', 'url' => 'https://parentpulse.freshdesk.com/support/solutions/articles/72000641595-how-to-change-primary-admin', 'keywords' => ['change primary admin', 'primary admin'] ],
            ['title' => 'How to Impersonate an Admin', 'url' => 'https://parentpulse.freshdesk.com/support/solutions/articles/72000641594-how-to-impersonate-an-admin', 'keywords' => ['impersonate', 'admin', 'login as'] ],
            ['title' => 'Multilingual Surveys', 'url' => 'https://parentpulse.freshdesk.com/support/solutions/articles/72000641593-multilingual-surveys', 'keywords' => ['multilingual', 'languages', 'translation'] ],

            ['title' => 'Benchmark Questions for Parent Pulse Surveys', 'url' => 'https://parentpulse.freshdesk.com/support/solutions/articles/72000641592-benchmark-questions-for-parent-pulse-surveys', 'keywords' => ['benchmark questions', 'question bank'] ],
            ['title' => 'Managing Sequences', 'url' => 'https://parentpulse.freshdesk.com/support/solutions/articles/72000641591-managing-sequences', 'keywords' => ['sequences', 'automation', 'schedule'] ],
            ['title' => 'Setting Quiet Periods', 'url' => 'https://parentpulse.freshdesk.com/support/solutions/articles/72000641590-setting-quiet-periods', 'keywords' => ['quiet periods', 'quiet', 'mute'] ],
            ['title' => 'Survey Builder Enhancements', 'url' => 'https://parentpulse.freshdesk.com/support/solutions/articles/72000641589-survey-builder-enhancements', 'keywords' => ['enhancements', 'builder updates'] ],
            ['title' => 'Using Custom Surveys for Admissions', 'url' => 'https://parentpulse.freshdesk.com/support/solutions/articles/72000641588-using-custom-surveys-for-admissions', 'keywords' => ['admissions', 'custom surveys'] ],

            ['title' => 'Creating and Using Saved Responses', 'url' => 'https://parentpulse.freshdesk.com/support/solutions/articles/72000641587-creating-and-using-saved-responses', 'keywords' => ['saved responses', 'templates'] ],
            ['title' => 'Disguise Your Identity!', 'url' => 'https://parentpulse.freshdesk.com/support/solutions/articles/72000641586-disguise-your-identity-', 'keywords' => ['disguise', 'anonymize', 'identity'] ],
            ['title' => 'Forwarding Comments: Best Practices', 'url' => 'https://parentpulse.freshdesk.com/support/solutions/articles/72000641585-forwarding-comments-best-practices', 'keywords' => ['forwarding comments', 'best practices'] ],
            ['title' => 'How to Forward Comments', 'url' => 'https://parentpulse.freshdesk.com/support/solutions/articles/72000641584-how-to-forward-comments', 'keywords' => ['forward comments', 'forwarding'] ],
            ['title' => 'How to Reply to Comments', 'url' => 'https://parentpulse.freshdesk.com/support/solutions/articles/72000641583-how-to-reply-to-comments', 'keywords' => ['reply to comments', 'respond comments'] ],

            ['title' => 'Accessing Survey History', 'url' => 'https://parentpulse.freshdesk.com/support/solutions/articles/72000641582-accessing-survey-history', 'keywords' => ['survey history', 'history'] ],
            ['title' => 'How to Add or Remove People', 'url' => 'https://parentpulse.freshdesk.com/support/solutions/articles/72000641581-how-to-add-or-remove-people', 'keywords' => ['add people', 'remove people', 'contacts'] ],
            ['title' => 'How to Extend Survey Expirations', 'url' => 'https://parentpulse.freshdesk.com/support/solutions/articles/72000641580-how-to-extend-survey-expirations', 'keywords' => ['extend expiration', 'expiry'] ],
            ['title' => 'How to Import Contact Information', 'url' => 'https://parentpulse.freshdesk.com/support/solutions/articles/72000641579-how-to-import-contact-information', 'keywords' => ['import contacts', 'contact information', 'csv'] ],

            ['title' => 'Comparing Data', 'url' => 'https://parentpulse.freshdesk.com/support/solutions/articles/72000641578-comparing-data', 'keywords' => ['compare data', 'comparison'] ],
            ['title' => 'Downloading Comments', 'url' => 'https://parentpulse.freshdesk.com/support/solutions/articles/72000641577-downloading-comments', 'keywords' => ['download comments', 'export comments'] ],
            ['title' => 'How to Export Your Data', 'url' => 'https://parentpulse.freshdesk.com/support/solutions/articles/72000641576-how-to-export-your-data', 'keywords' => ['export data', 'download data'] ],
            ['title' => 'Pin Questions to Your Homepage', 'url' => 'https://parentpulse.freshdesk.com/support/solutions/articles/72000641575-pin-questions-to-your-homepage', 'keywords' => ['pin questions', 'homepage'] ],
            ['title' => 'View Individual Survey Responses', 'url' => 'https://parentpulse.freshdesk.com/support/solutions/articles/72000641574-view-individual-survey-responses', 'keywords' => ['individual responses', 'view responses'] ],
        ];
    }

    /**
     * Return the best matching article for a message.
     *
     * @return array{title:string,url:string}|null
     */
    public function findBestMatch(string $message): ?array
    {
        $text = Str::of($message)->lower()->toString();
        $best = null;
        $bestScore = 0;

        foreach ($this->articles() as $article) {
            $score = 0;
            foreach ($article['keywords'] as $kw) {
                if (Str::contains($text, Str::of($kw)->lower()->toString())) {
                    $score += 1;
                }
            }
            // Soft title match weight
            if (Str::contains($text, Str::of($article['title'])->lower()->toString())) {
                $score += 2;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = ['title' => $article['title'], 'url' => $article['url']];
            }
        }

        return $bestScore > 0 ? $best : null;
    }

    /**
     * Fetch article HTML and return plain text.
     */
    public function fetchArticleText(string $url): ?string
    {
        $response = Http::timeout(15)->get($url);
        if (! $response->successful()) {
            return null;
        }
        $html = $response->body();

        // Basic cleanup; can be replaced with a DOM parser if needed
        $html = preg_replace('/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/i', ' ', $html ?? '');
        $html = preg_replace('/<style\b[^<]*(?:(?!<\/style>)<[^<]*)*<\/style>/i', ' ', $html ?? '');
        $text = strip_tags($html ?? '');
        $text = html_entity_decode($text ?? '', ENT_QUOTES | ENT_HTML5);
        $text = preg_replace('/\s+/', ' ', $text ?? '');
        return trim((string) $text);
    }
}


