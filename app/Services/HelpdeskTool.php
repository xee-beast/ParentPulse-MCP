<?php

namespace App\Services;

use Illuminate\Support\Str;

class HelpdeskTool
{
    /**
     * Generate a helpdesk-oriented response for a user's message.
     */
    public function generateHelpdeskResponse(string $userMessage, string $tenantId): array
    {
        $normalized = Str::of($userMessage)->trim()->lower();

        $category = $this->determineCategory($normalized);

        $answer = match ($category) {
            'greeting' => "Hi! How can I help you today?",
            'billing' => "For billing questions, please check your subscription and invoices in Settings > Billing.",
            'technical' => "Please describe the issue you're facing. I can help troubleshoot common problems.",
            default => "I can help with general questions, onboarding, and troubleshooting. Tell me more.",
        };

        return [
            'type' => 'helpdesk',
            'tenant_id' => $tenantId,
            'category' => $category,
            'answer' => $answer,
        ];
    }

    private function determineCategory(string $normalized): string
    {
        if ($normalized === '' || Str::contains($normalized, ['hello', 'hi', 'hey'])) {
            return 'greeting';
        }

        if (Str::contains($normalized, ['invoice', 'billing', 'payment', 'subscription'])) {
            return 'billing';
        }

        if (Str::contains($normalized, ['error', 'bug', 'issue', 'not working', 'failed'])) {
            return 'technical';
        }

        return 'general';
    }
}


