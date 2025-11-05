<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class KnowledgeBaseService
{
    private ?array $kbData = null;
    private string $kbPath;
    private ?string $tenantId = null;

    public function __construct()
    {
        $this->tenantId = (string) (app('tenant_id') ?? '');
        $this->kbPath = public_path("kb/{$this->tenantId}.json");
    }

    /**
     * Load KB data with caching and lazy loading
     */
    public function loadData(): array
    {
        if ($this->kbData !== null) {
            return $this->kbData;
        }

        $cacheKey = "kb_data_{$this->tenantId}";

        //TODO: Remove this after testing
        Cache::forget($cacheKey);
        
        // Check cache first (cache for 1 hour)
        $this->kbData = Cache::remember($cacheKey, 3600, function () {
            if (!file_exists($this->kbPath)) {
                Log::warning("KB file not found: {$this->kbPath}");
                return [];
            }

            // For large files, use streaming JSON parser
            $fileSize = filesize($this->kbPath);
            $isLarge = $fileSize > 10 * 1024 * 1024; // > 10MB

            if ($isLarge) {
                return $this->loadLargeFile();
            }

            // For smaller files, load normally
            $content = file_get_contents($this->kbPath);
            $data = json_decode($content, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error("KB JSON parse error: " . json_last_error_msg());
                return [];
            }

            return $data;
        });

        return $this->kbData;
    }

    /**
     * Load large JSON file efficiently
     */
    private function loadLargeFile(): array
    {
        // Use stream_context for large files
        $content = file_get_contents($this->kbPath);
        $data = json_decode($content, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error("KB JSON parse error: " . json_last_error_msg());
            return [];
        }

        return $data;
    }

    /**
     * Extract relevant data based on query intent
     */
    public function extractRelevantData(string $query): array
    {
        $data = $this->loadData();
        if (empty($data)) {
            return [];
        }

        $normalized = Str::of($query)->lower();
        $normalizedStr = (string) $normalized;
        
        // Determine what data is needed
        $needsSurveyData = $this->needsSurveyData($normalizedStr);
        $needsNps = $this->needsNps($normalizedStr);
        $needsRoster = $this->needsRoster($normalizedStr);
        $needsAdmin = $this->needsAdmin($normalizedStr);
        $needsCycle = $this->needsCycle($normalizedStr);
        $needsQuestion = $this->needsQuestion($normalizedStr);
        $needsDemographic = $this->needsDemographic($normalizedStr);
        
        // If query is about surveys/NPS/satisfaction, don't load roster data unless explicitly asked
        // (e.g., "how many parents" without survey context)
        $isSurveyQuery = $needsSurveyData || $needsNps || $needsQuestion;
        if ($isSurveyQuery && $needsRoster) {
            // Only include roster if explicitly asking for counts without survey context
            $explicitRosterRequest = Str::contains($normalizedStr, ['how many', 'count', 'number of']) &&
                                     !Str::contains($normalizedStr, ['survey', 'response', 'answer', 'nps', 'cycle', 'sequence']);
            if (!$explicitRosterRequest) {
                $needsRoster = false;
            }
        }
        
        // Extract time filters
        $timeFilter = $this->extractTimeFilter($normalizedStr);
        
        // Extract module filter
        $moduleFilter = $this->extractModuleFilter($normalizedStr);
        
        // Extract cycle filter
        $cycleFilter = $this->extractCycleFilter($normalizedStr);
        
        // Check if this is a multi-cycle comparison query
        $isMultiCycleComparison = $this->isMultiCycleComparison($normalizedStr);
        
        // Extract survey type filter (pulse vs custom)
        $surveyTypeFilter = $this->extractSurveyTypeFilter($normalizedStr);

        $result = [
            'tenant' => $data['tenant'] ?? null,
        ];

        // Only include what's needed
        if ($needsAdmin) {
            $result['admins'] = $data['admins'] ?? [];
        }

        if ($needsRoster) {
            if ($moduleFilter === 'parent' || $moduleFilter === null) {
                $result['parents'] = $this->filterByTime($data['parents'] ?? [], $timeFilter);
            }
            if ($moduleFilter === 'student' || $moduleFilter === null) {
                $result['students'] = $this->filterByTime($data['students'] ?? [], $timeFilter);
            }
            if ($moduleFilter === 'employee' || $moduleFilter === null) {
                $result['employees'] = $this->filterByTime($data['employees'] ?? [], $timeFilter);
            }
        }

        if ($needsSurveyData || $needsNps || $needsQuestion || $needsCycle) {
            $surveyData = $data['survey_data'] ?? [];
            
            Log::debug("KnowledgeBaseService: Before filtering", [
                'query' => $query,
                'raw_survey_data_count' => count($surveyData),
                'time_filter' => $timeFilter,
                'module_filter' => $moduleFilter,
                'cycle_filter' => $cycleFilter,
                'status_filter' => $this->extractStatusFilter($normalized),
                'survey_type_filter' => $surveyTypeFilter,
            ]);
            
            // For multi-cycle comparisons, extract cycles and filter by them
            $cyclesToFilter = null;
            $singleCycleFilter = null;
            if ($isMultiCycleComparison) {
                $cyclesToFilter = $this->extractAllCycles($normalizedStr);
            } else {
                $singleCycleFilter = $cycleFilter;
            }
            
            // Apply filters
            $surveyData = $this->filterSurveyData($surveyData, [
                'time' => $timeFilter,
                'module' => $moduleFilter,
                'cycle' => $singleCycleFilter, // Single cycle filter (for non-multi-cycle queries)
                'cycles' => $cyclesToFilter, // Multiple cycles filter (for multi-cycle queries)
                'question' => $needsQuestion ? $this->extractQuestionText($query) : null,
                'status' => $this->extractStatusFilter($normalized),
                'survey_type' => $surveyTypeFilter,
            ]);

            Log::debug("KnowledgeBaseService: After filtering", [
                'filtered_survey_data_count' => count($surveyData),
            ]);

            // Optimize survey data structure to reduce context size
            // This removes unnecessary fields and keeps only relevant answer types based on query intent
            // For NPS queries: keeps only NPS answers with optimized structure
            // For comment queries: keeps only comments
            // For benchmark queries: keeps only benchmarks
            // For demographic queries: keeps filtering answers
            // For multi-cycle: uses specialized optimization
            $surveyData = $this->optimizeSurveyData($surveyData, [
                'needsNps' => $needsNps,
                'needsQuestion' => $needsQuestion,
                'needsDemographic' => $needsDemographic,
                'isMultiCycle' => $isMultiCycleComparison,
                'query' => $query,
            ]);

            // If demographic grouping, add demographic extraction
            if ($needsDemographic) {
                $result['demographic_question'] = $this->findDemographicQuestion($data);
            }

            // Mark as multi-cycle comparison if detected
            if ($isMultiCycleComparison) {
                $result['multi_cycle_comparison'] = true;
                $result['extracted_cycles'] = $this->extractAllCycles($normalizedStr);
                
                // Optimize survey_data for multi-cycle comparison - extract only essential fields
                // This reduces context from ~6MB to ~200KB by keeping only: respondent_name, survey_cycle, nps_score
                $surveyData = $this->optimizeForMultiCycleComparison($surveyData);
            }

            $result['survey_data'] = $surveyData;
            $result['survey_cycles'] = $data['survey_cycles'] ?? [];
            
            // Calculate NPS if needed (but not for multi-cycle comparisons - they need individual scores)
            // For question-specific queries, calculate NPS from answers to that specific question
            if ($needsNps && !empty($surveyData) && !$isMultiCycleComparison) {
                // If query mentions multiple modules or satisfaction query, calculate NPS per module
                $queryLower = strtolower($query);
                $mentionsMultipleModules = (
                    (Str::contains($queryLower, 'parent') && Str::contains($queryLower, 'student')) ||
                    (Str::contains($queryLower, 'parent') && Str::contains($queryLower, 'employee')) ||
                    (Str::contains($queryLower, 'student') && Str::contains($queryLower, 'employee')) ||
                    (Str::contains($queryLower, 'all') && Str::contains($queryLower, ['parent', 'student', 'employee']))
                );
                
                // For question-specific NPS queries with demographic grouping, handle separately
                if ($needsQuestion && $needsDemographic) {
                    // This will be handled by extracting NPS grouped by demographic
                    // Don't calculate overall NPS here
                } elseif ($mentionsMultipleModules || ($moduleFilter === null && Str::contains($queryLower, ['happy', 'satisfied', 'doing well', 'overall']))) {
                    // Calculate NPS per module
                    $npsByModule = [];
                    foreach (['parent', 'student', 'employee'] as $module) {
                        $moduleData = array_filter($surveyData, function($survey) use ($module) {
                            return ($survey['respondent_type'] ?? '') === $module;
                        });
                        if (!empty($moduleData)) {
                            // For question-specific queries, calculate NPS from that question only
                            $moduleNps = $needsQuestion 
                                ? $this->calculateNpsFromQuestion($query, array_values($moduleData))
                                : $this->calculateNpsFromSurveyData(array_values($moduleData));
                            if ($moduleNps !== null) {
                                $npsByModule[$module] = $moduleNps;
                            }
                        }
                    }
                    if (!empty($npsByModule)) {
                        $result['nps_calculation'] = $npsByModule;
                    }
                } else {
                    // Calculate overall NPS (or NPS for specific question)
                    $npsCalculation = $needsQuestion
                        ? $this->calculateNpsFromQuestion($query, $surveyData)
                        : $this->calculateNpsFromSurveyData($surveyData);
                    if ($npsCalculation !== null) {
                        $result['nps_calculation'] = $npsCalculation;
                    }
                }
            }
        }

        // Always include survey_cycles if query mentions cycles/sequences (even without survey keyword)
        if ($needsCycle && !isset($result['survey_cycles'])) {
            $result['survey_cycles'] = $data['survey_cycles'] ?? [];
        }

        return $result;
    }

    /**
     * Check if query needs survey data
     */
    private function needsSurveyData(string $normalized): bool
    {
        $keywords = ['response', 'responses', 'answer', 'answers', 'survey', 'nps', 'score', 'question', 'respondent', 'sent', 'answered'];
        foreach ($keywords as $keyword) {
            if (Str::contains($normalized, $keyword)) {
                return true;
            }
        }
        
        // Satisfaction/happiness queries - these need survey data
        $satisfactionKeywords = ['happy', 'satisfied', 'satisfaction', 'doing well', 'doing good', 'how are we', 'how is', 'overall', 'feel', 'feeling', 'experience', 'opinion', 'feedback'];
        foreach ($satisfactionKeywords as $keyword) {
            if (Str::contains($normalized, $keyword)) {
                return true;
            }
        }
        
        // Also treat cycle/sequence as survey-related
        if (Str::contains($normalized, ['cycle', 'cycles', 'sequence', 'sequences'])) {
            return true;
        }
        
        return false;
    }

    /**
     * Check if query needs NPS data
     * Enhanced to detect satisfaction/happiness queries that should include NPS analysis
     */
    private function needsNps(string $normalized): bool
    {
        // Explicit NPS keywords
        $keywords = ['nps', 'net promoter', 'promoter score', 'detractor', 'promoter', 'passive'];
        foreach ($keywords as $keyword) {
            if (Str::contains($normalized, $keyword)) {
                return true;
            }
        }
        
        // Satisfaction/happiness/performance queries - these should include NPS
        $satisfactionKeywords = [
            'happy', 'satisfied', 'satisfaction', 
            'doing well', 'doing good', 'how are we', 'how is', 
            'overall', 'feel', 'feeling', 'experience', 
            'opinion', 'feedback', 'popularity', 'popular',
            'recommend', 'recommendation', 'improve', 'increase',
            'better', 'steps to', 'ways to', 'how to improve',
            'how to increase', 'make better', 'performing', 'performance'
        ];
        foreach ($satisfactionKeywords as $keyword) {
            if (Str::contains($normalized, $keyword)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Check if query needs roster data
     */
    private function needsRoster(string $normalized): bool
    {
        $hasRosterKeywords = false;
        $keywords = ['how many', 'count', 'list', 'show', 'get', 'give me', 'parent', 'student', 'employee', 'admin', 'email', 'name'];
        foreach ($keywords as $keyword) {
            if (Str::contains($normalized, $keyword)) {
                $hasRosterKeywords = true;
                break;
            }
        }
        
        if (!$hasRosterKeywords) {
            return false;
        }
        
        // Exclude if it's about survey responses, cycles, sequences, or surveys
        $excludeKeywords = ['response', 'answer', 'survey', 'cycle', 'cycles', 'sequence', 'sequences'];
        foreach ($excludeKeywords as $keyword) {
            if (Str::contains($normalized, $keyword)) {
                return false;
            }
        }
        
        return true;
    }

    /**
     * Check if query needs admin data
     */
    private function needsAdmin(string $normalized): bool
    {
        $keywords = ['admin', 'administrator', 'owner', 'management'];
        foreach ($keywords as $keyword) {
            if (Str::contains($normalized, $keyword)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if query needs cycle data
     */
    private function needsCycle(string $normalized): bool
    {
        $keywords = ['cycle', 'sequence', 'period', 'fall', 'spring', 'winter', 'summer', 'sprint'];
        foreach ($keywords as $keyword) {
            if (Str::contains($normalized, $keyword)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if query needs question-specific data
     */
    private function needsQuestion(string $normalized): bool
    {
        // Explicit question keywords
        if (Str::contains($normalized, ['question', 'for the question', 'on the question', 'get me the result'])) {
            return true;
        }
        
        // Check for quoted text (question text)
        if (preg_match('/["\']([^"\']+)["\']/', $normalized)) {
            return true;
        }
        
        // Check for patterns like "For the question, 'The school campus...'"
        if (preg_match('/for the question[,\s]+[\'"]([^\'"]+)[\'"]/i', $normalized)) {
            return true;
        }
        
        return false;
    }

    /**
     * Check if query needs demographic grouping
     */
    private function needsDemographic(string $normalized): bool
    {
        $keywords = ['each', 'for each', 'by', 'grouped by', 'group by', 'grade', 'grades', 'grade level', 'demographic', 'segment'];
        foreach ($keywords as $keyword) {
            if (Str::contains($normalized, $keyword)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Extract time filter from query
     */
    private function extractTimeFilter(string $normalized): ?array
    {
        $timePatterns = [
            // 3 months - numeric and spelled out
            ['patterns' => ['last 3 months', 'past 3 months', '3 months', 'last three months', 'past three months', 'three months'], 'filter' => ['months' => 3]],
            // 1 month
            ['patterns' => ['last month', 'past month', '1 month', 'last one month', 'past one month', 'one month'], 'filter' => ['months' => 1]],
            // 6 months
            ['patterns' => ['last 6 months', 'past 6 months', '6 months', 'last six months', 'past six months', 'six months'], 'filter' => ['months' => 6]],
            // 1 year
            ['patterns' => ['last year', 'past year', '1 year', 'last one year', 'past one year', 'one year'], 'filter' => ['months' => 12]],
            // 30 days
            ['patterns' => ['last 30 days', 'past 30 days', '30 days', 'last thirty days', 'past thirty days', 'thirty days'], 'filter' => ['days' => 30]],
            // 60 days
            ['patterns' => ['last 60 days', 'past 60 days', '60 days', 'last sixty days', 'past sixty days', 'sixty days'], 'filter' => ['days' => 60]],
            // 90 days
            ['patterns' => ['last 90 days', 'past 90 days', '90 days', 'last ninety days', 'past ninety days', 'ninety days'], 'filter' => ['days' => 90]],
            // Generic recent
            ['patterns' => ['recent', 'recently'], 'filter' => ['days' => 90]],
        ];

        foreach ($timePatterns as $patternGroup) {
            foreach ($patternGroup['patterns'] as $pattern) {
                if (Str::contains($normalized, $pattern)) {
                    return $patternGroup['filter'];
                }
            }
        }

        return null;
    }

    /**
     * Extract module filter from query
     * Returns null if multiple modules are mentioned (to include all)
     */
    private function extractModuleFilter(string $normalized): ?string
    {
        $hasParent = Str::contains($normalized, 'parent');
        $hasStudent = Str::contains($normalized, 'student');
        $hasEmployee = Str::contains($normalized, ['employee', 'staff']);
        
        $moduleCount = 0;
        if ($hasParent) $moduleCount++;
        if ($hasStudent) $moduleCount++;
        if ($hasEmployee) $moduleCount++;
        
        // If multiple modules mentioned, return null to include all
        if ($moduleCount > 1) {
            return null;
        }
        
        // Otherwise return the single module
        if ($hasParent) {
            return 'parent';
        }
        if ($hasStudent) {
            return 'student';
        }
        if ($hasEmployee) {
            return 'employee';
        }
        
        return null;
    }

    /**
     * Extract survey type filter from query
     */
    private function extractSurveyTypeFilter(string $normalized): ?string
    {
        // If user explicitly asks for custom surveys
        if (Str::contains($normalized, ['custom survey', 'custom surveys', 'custom'])) {
            return 'custom';
        }
        
        // Don't filter by survey_type unless explicitly requested
        // This allows backward compatibility with JSON files that don't have survey_type
        return null;
    }

    /**
     * Extract cycle filter from query
     */
    private function extractCycleFilter(string $normalized): ?string
    {
        // Match cycle patterns
        if (preg_match('/\b(spring|summer|fall|winter|sprint)\s+(\d{2,4})\b/i', $normalized, $matches)) {
            return $matches[0];
        }
        if (preg_match('/\b(\d{2,4}-\d{2,4})\s+(fall|spring|summer|winter)\b/i', $normalized, $matches)) {
            return $matches[0];
        }
        return null;
    }

    /**
     * Check if query is a multi-cycle comparison
     */
    private function isMultiCycleComparison(string $normalized): bool
    {
        // Check for comparison keywords
        $hasComparisonKeywords = Str::contains($normalized, [
            'improved', 'improvement', 'improve', 'changed', 'change',
            'from', 'to', 'between', 'across', 'moved', 'moved to',
            'compared', 'comparison'
        ]);
        
        // Count cycle mentions
        $cycleMatches = [];
        preg_match_all('/\b(spring|summer|fall|winter|sprint)\s+\d{2,4}\b/i', $normalized, $cycleMatches);
        $cycleCount = count($cycleMatches[0]);
        
        // Also check for cycle/sequence keywords with context
        if (Str::contains($normalized, ['cycle', 'cycles', 'sequence', 'sequences']) && $hasComparisonKeywords) {
            $cycleCount++;
        }
        
        return $hasComparisonKeywords && $cycleCount >= 2;
    }

    /**
     * Extract all cycle names from query
     */
    private function extractAllCycles(string $normalized): array
    {
        $cycles = [];
        
        // Match cycle patterns
        if (preg_match_all('/\b(spring|summer|fall|winter|sprint)\s+(\d{2,4})\b/i', $normalized, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $season = strtolower(trim($match[1]));
                $year = trim($match[2]);
                
                // Normalize "sprint" to "spring" for matching
                if ($season === 'sprint') {
                    $season = 'spring';
                }
                
                $cycleName = ucfirst($season) . ' ' . $year;
                if (!in_array($cycleName, $cycles)) {
                    $cycles[] = $cycleName;
                }
            }
        }
        
        return $cycles;
    }

    /**
     * Extract question text from query
     * Handles various formats:
     * - "get me the result on this question - There are ample opportunities..."
     * - "For the question, 'The school campus is a safe place'"
     * - "For the question The school campus is a safe place"
     */
    private function extractQuestionText(string $query): ?string
    {
        // Pattern 1: Text in quotes (single or double)
        if (preg_match('/["\']([^"\']+)["\']/', $query, $matches)) {
            return trim($matches[1]);
        }
        
        // Pattern 2: "For the question, '...'" or "For the question '...'"
        if (preg_match('/for the question[,\s]+[\'"]([^\'"]+)[\'"]/i', $query, $matches)) {
            return trim($matches[1]);
        }
        
        // Pattern 3: "get me the result on this question - [question text]"
        if (preg_match('/get me the result on this question\s*-\s*(.+?)(?:\?|$)/i', $query, $matches)) {
            return trim($matches[1]);
        }
        
        // Pattern 4: "question [text]" or "on the question [text]"
        if (preg_match('/(?:question|on the question|for the question)\s+(.+?)(?:\?|$|,)/i', $query, $matches)) {
            $text = trim($matches[1]);
            // Remove common trailing phrases
            $text = preg_replace('/\s+(can you|give me|tell me|show me).*$/i', '', $text);
            return $text;
        }
        
        return null;
    }

    /**
     * Extract status filter
     */
    private function extractStatusFilter(string $normalized): ?string
    {
        $keywords = ['completed', 'finished', 'answered'];
        foreach ($keywords as $keyword) {
            if (Str::contains($normalized, $keyword)) {
                return 'completed';
            }
        }
        
        $keywords = ['partial', 'incomplete'];
        foreach ($keywords as $keyword) {
            if (Str::contains($normalized, $keyword)) {
                return 'partial';
            }
        }
        
        $keywords = ['sent', 'pending'];
        foreach ($keywords as $keyword) {
            if (Str::contains($normalized, $keyword)) {
                return 'sent';
            }
        }
        
        return null;
    }

    /**
     * Filter survey data based on criteria
     */
    private function filterSurveyData(array $surveyData, array $filters): array
    {
        $filtered = [];
        $debugStats = [
            'total' => count($surveyData),
            'filtered_by_time' => 0,
            'filtered_by_module' => 0,
            'filtered_by_cycle' => 0,
            'filtered_by_status' => 0,
            'filtered_by_survey_type' => 0,
            'filtered_by_question' => 0,
            'no_date' => 0,
            'date_parse_failed' => 0,
            'passed' => 0,
        ];

        foreach ($surveyData as $survey) {
            // Time filter - use sent_at (matches SQL: si.created_at)
            if (isset($filters['time'])) {
                $timeFilter = $filters['time'];
                $sentAt = $survey['sent_at'] ?? null;
                
                // Fallback to answered_at if sent_at not available
                if ($sentAt === null) {
                    $sentAt = $survey['answered_at'] ?? null;
                }
                
                if ($sentAt === null) {
                    $debugStats['no_date']++;
                    continue; // Skip if no date available
                }
                
                // Handle different date formats from JSON files vs Supabase
                // JSON files: "2025-11-04 18:34:14" or "2025-11-04 18:34:14.579771"
                // Supabase: "2025-11-04T18:34:14.579771+00:00" (ISO 8601)
                $checkDate = null;
                
                // Try ISO 8601 format first (Supabase format)
                if (strpos($sentAt, 'T') !== false) {
                    try {
                        $checkDate = new \DateTime($sentAt);
                    } catch (\Exception $e) {
                        // Fall through to other formats
                    }
                }
                
                // Try standard format: Y-m-d H:i:s
                if (!$checkDate) {
                    $checkDate = \DateTime::createFromFormat('Y-m-d H:i:s', $sentAt);
                }
                
                // Try format with microseconds: Y-m-d H:i:s.u
                if (!$checkDate) {
                    $checkDate = \DateTime::createFromFormat('Y-m-d H:i:s.u', $sentAt);
                }
                
                // Try date-only format as last resort: Y-m-d
                if (!$checkDate) {
                    $checkDate = \DateTime::createFromFormat('Y-m-d', $sentAt);
                }
                
                if (!$checkDate) {
                    $debugStats['date_parse_failed']++;
                    Log::debug("Could not parse date format", [
                        'date' => $sentAt,
                        'survey_id' => $survey['id'] ?? 'unknown',
                    ]);
                    continue;
                }
                
                // Ensure timezone is set correctly for comparison
                $checkDate->setTimezone(new \DateTimeZone('UTC'));

                $now = new \DateTime('now', new \DateTimeZone('UTC'));
                if (isset($timeFilter['days'])) {
                    $cutoff = (clone $now)->modify("-{$timeFilter['days']} days");
                    if ($checkDate < $cutoff) {
                        $debugStats['filtered_by_time']++;
                        continue;
                    }
                } elseif (isset($timeFilter['months'])) {
                    $cutoff = (clone $now)->modify("-{$timeFilter['months']} months");
                    if ($checkDate < $cutoff) {
                        $debugStats['filtered_by_time']++;
                        continue;
                    }
                }
            }

            // Module filter
            if (isset($filters['module']) && $filters['module'] !== null) {
                $respondentType = strtolower($survey['respondent_type'] ?? '');
                if ($respondentType !== $filters['module']) {
                    $debugStats['filtered_by_module']++;
                    continue;
                }
            }

            // Cycle filter (single cycle)
            if (isset($filters['cycle']) && $filters['cycle'] !== null) {
                $cycleLabel = strtolower($survey['survey_cycle'] ?? '');
                if ($cycleLabel === null || $cycleLabel === '' || !Str::contains($cycleLabel, strtolower($filters['cycle']))) {
                    $debugStats['filtered_by_cycle']++;
                    continue;
                }
            }

            // Cycles filter (multiple cycles for multi-cycle comparisons)
            if (isset($filters['cycles']) && $filters['cycles'] !== null && is_array($filters['cycles']) && !empty($filters['cycles'])) {
                $cycleLabel = strtolower($survey['survey_cycle'] ?? '');
                
                // Skip surveys without a cycle label
                if ($cycleLabel === null || $cycleLabel === '') {
                    $debugStats['filtered_by_cycle']++;
                    continue;
                }
                
                $matchesAnyCycle = false;
                
                foreach ($filters['cycles'] as $cycleToMatch) {
                    $cycleToMatchLower = strtolower($cycleToMatch);
                    
                    // Extract keywords from cycle name (e.g., "Spring 2025" -> ["spring", "2025"])
                    $cycleKeywords = array_filter(array_map('trim', preg_split('/\s+/', $cycleToMatchLower)));
                    
                    // Check if all keywords are present in the survey cycle label
                    $allKeywordsMatch = true;
                    foreach ($cycleKeywords as $keyword) {
                        if (!Str::contains($cycleLabel, $keyword)) {
                            $allKeywordsMatch = false;
                            break;
                        }
                    }
                    
                    if ($allKeywordsMatch) {
                        $matchesAnyCycle = true;
                        break;
                    }
                }
                
                if (!$matchesAnyCycle) {
                    continue; // Survey doesn't match any of the cycles we're comparing
                }
            }

            // Status filter
            if (isset($filters['status']) && $filters['status'] !== null) {
                $status = strtolower($survey['status'] ?? '');
                if ($status !== $filters['status']) {
                    continue;
                }
            }

            // Question filter
            if (isset($filters['question']) && $filters['question'] !== null) {
                $questionText = $filters['question'];
                $hasQuestion = false;
                
                foreach ($survey['answers'] ?? [] as $answer) {
                    $answerQuestion = strtolower($answer['question'] ?? '');
                    $searchText = strtolower($questionText);
                    
                    // Remove [CLIENT_NAME] placeholder for matching
                    $answerQuestion = str_replace('[client_name]', '', $answerQuestion);
                    $searchText = str_replace('[client_name]', '', $searchText);
                    
                    if (Str::contains($answerQuestion, $searchText) || Str::contains($searchText, $answerQuestion)) {
                        $hasQuestion = true;
                        break;
                    }
                }
                
                if (!$hasQuestion) {
                    continue;
                }
            }

            // Survey type filter (pulse vs custom)
            if (isset($filters['survey_type']) && $filters['survey_type'] !== null) {
                $surveyType = strtolower($survey['survey_type'] ?? '');
                
                // If survey doesn't have survey_type set, default to pulse for backward compatibility
                if ($surveyType === '') {
                    $surveyType = 'pulse';
                }
                
                if ($surveyType !== $filters['survey_type']) {
                    continue;
                }
            }

            $filtered[] = $survey;
            $debugStats['passed']++;
        }
        
        Log::debug("KnowledgeBaseService: Filter statistics", $debugStats);

        return $filtered;
    }

    /**
     * Calculate NPS from survey data for a specific question
     */
    private function calculateNpsFromQuestion(string $query, array $surveyData): ?array
    {
        $questionText = $this->extractQuestionText($query);
        if ($questionText === null) {
            // Fallback to overall NPS calculation
            return $this->calculateNpsFromSurveyData($surveyData);
        }
        
        $promoters = 0;
        $passives = 0;
        $detractors = 0;
        $total = 0;
        
        $searchText = strtolower($questionText);
        $searchText = str_replace('[client_name]', '', $searchText);
        
        foreach ($surveyData as $survey) {
            foreach ($survey['answers'] ?? [] as $answer) {
                // Only process NPS type answers
                if (($answer['type'] ?? '') !== 'nps') {
                    continue;
                }
                
                // Check if this answer is for the requested question
                $answerQuestion = strtolower($answer['question'] ?? '');
                $answerQuestion = str_replace('[client_name]', '', $answerQuestion);
                
                // Match question text (flexible matching)
                if (!Str::contains($answerQuestion, $searchText) && !Str::contains($searchText, $answerQuestion)) {
                    continue; // Skip answers that don't match the question
                }
                
                // Get NPS value
                $value = null;
                if (isset($answer['nps']['score'])) {
                    $value = is_numeric($answer['nps']['score']) ? (int)$answer['nps']['score'] : null;
                } elseif (isset($answer['value'])) {
                    $value = is_numeric($answer['value']) ? (int)$answer['value'] : null;
                    if ($value === null && is_string($answer['value'])) {
                        $value = filter_var($answer['value'], FILTER_VALIDATE_INT);
                    }
                } elseif (isset($answer['rating'])) {
                    $value = is_numeric($answer['rating']) ? (int)$answer['rating'] : null;
                } elseif (isset($answer['nps_score'])) {
                    $value = is_numeric($answer['nps_score']) ? (int)$answer['nps_score'] : null;
                }

                // Validate NPS score (0-10)
                if ($value === null || $value < 0 || $value > 10) {
                    continue;
                }

                $total++;
                
                // Categorize
                if ($value >= 9) {
                    $promoters++;
                } elseif ($value >= 7) {
                    $passives++;
                } else {
                    $detractors++;
                }
            }
        }

        if ($total === 0) {
            return null;
        }

        // Calculate NPS: (% Promoters - % Detractors) * 100
        $promoterPercent = ($promoters / $total) * 100;
        $detractorPercent = ($detractors / $total) * 100;
        $passivePercent = ($passives / $total) * 100;
        $npsScore = round(($promoterPercent - $detractorPercent), 1);

        return [
            'nps_score' => $npsScore,
            'total_responses' => $total,
            'promoters' => $promoters,
            'passives' => $passives,
            'detractors' => $detractors,
            'promoter_percent' => round($promoterPercent, 1),
            'passive_percent' => round($passivePercent, 1),
            'detractor_percent' => round($detractorPercent, 1),
            'question' => $questionText, // Include question text for reference
        ];
    }

    public function calculateNpsFromSurveyData(array $surveyData): ?array
    {
        $npsScores = [];
        $promoters = 0;
        $passives = 0;
        $detractors = 0;
        $total = 0;

        foreach ($surveyData as $survey) {
            foreach ($survey['answers'] ?? [] as $answer) {
                if (($answer['type'] ?? '') !== 'nps') {
                    continue;
                }

                // Get NPS value - check nps.score first (KB format), then value, then rating
                $value = null;
                if (isset($answer['nps']['score'])) {
                    $value = is_numeric($answer['nps']['score']) ? (int)$answer['nps']['score'] : null;
                } elseif (isset($answer['value'])) {
                    // Handle both string and numeric values
                    $value = is_numeric($answer['value']) ? (int)$answer['value'] : null;
                    if ($value === null && is_string($answer['value'])) {
                        $value = filter_var($answer['value'], FILTER_VALIDATE_INT);
                    }
                } elseif (isset($answer['rating'])) {
                    $value = is_numeric($answer['rating']) ? (int)$answer['rating'] : null;
                }

                // Validate NPS score (0-10)
                if ($value === null || $value < 0 || $value > 10) {
                    continue;
                }

                $total++;
                $npsScores[] = $value;

                // Categorize
                if ($value >= 9) {
                    $promoters++;
                } elseif ($value >= 7) {
                    $passives++;
                } else {
                    $detractors++;
                }
            }
        }

        if ($total === 0) {
            return null;
        }

        // Calculate NPS: (% Promoters - % Detractors) * 100
        $promoterPercent = ($promoters / $total) * 100;
        $detractorPercent = ($detractors / $total) * 100;
        $passivePercent = ($passives / $total) * 100;
        $npsScore = round(($promoterPercent - $detractorPercent), 1);

        return [
            'nps_score' => $npsScore,
            'total_responses' => $total,
            'promoters' => $promoters,
            'passives' => $passives,
            'detractors' => $detractors,
            'promoter_percent' => round($promoterPercent, 1),
            'passive_percent' => round($passivePercent, 1),
            'detractor_percent' => round($detractorPercent, 1),
        ];
    }

    /**
     * Extract only NPS answers from survey data
     */
    private function extractNpsAnswers(array $surveyData): array
    {
        $result = [];
        
        foreach ($surveyData as $survey) {
            $npsAnswers = [];
            foreach ($survey['answers'] ?? [] as $answer) {
                if (($answer['type'] ?? '') === 'nps') {
                    $npsAnswers[] = $answer;
                }
            }
            
            if (!empty($npsAnswers)) {
                $surveyCopy = $survey;
                $surveyCopy['answers'] = $npsAnswers;
                $result[] = $surveyCopy;
            }
        }
        
        return $result;
    }

    /**
     * Filter roster data by time
     */
    private function filterByTime(array $items, ?array $timeFilter): array
    {
        if ($timeFilter === null) {
            return $items;
        }

        $filtered = [];
        foreach ($items as $item) {
            $createdAt = $item['created_at'] ?? null;
            if ($createdAt === null) {
                $filtered[] = $item;
                continue;
            }

            $createdDate = \DateTime::createFromFormat('Y-m-d H:i:s', $createdAt);
            if (!$createdDate) {
                $filtered[] = $item;
                continue;
            }

            $now = new \DateTime();
            if (isset($timeFilter['days'])) {
                $cutoff = (clone $now)->modify("-{$timeFilter['days']} days");
                if ($createdDate >= $cutoff) {
                    $filtered[] = $item;
                }
            } elseif (isset($timeFilter['months'])) {
                $cutoff = (clone $now)->modify("-{$timeFilter['months']} months");
                if ($createdDate >= $cutoff) {
                    $filtered[] = $item;
                }
            }
        }

        return $filtered;
    }

    /**
     * Find demographic question (grade, etc.)
     */
    private function findDemographicQuestion(array $data): ?array
    {
        // Search in survey_data for filtering questions
        $surveyData = $data['survey_data'] ?? [];
        $gradeQuestions = [];
        
        foreach ($surveyData as $survey) {
            foreach ($survey['answers'] ?? [] as $answer) {
                if (($answer['type'] ?? '') === 'filtering') {
                    $question = strtolower($answer['question'] ?? '');
                    if (Str::contains($question, 'grade')) {
                        $gradeQuestions[$answer['question']] = true;
                    }
                }
            }
        }
        
        if (!empty($gradeQuestions)) {
            return [
                'type' => 'filtering',
                'question' => array_key_first($gradeQuestions),
            ];
        }
        
        return null;
    }

    /**
     * Optimize survey data structure to reduce context size
     * Removes unnecessary fields and keeps only relevant answer types based on query intent
     */
    private function optimizeSurveyData(array $surveyData, array $hints): array
    {
        $needsNps = $hints['needsNps'] ?? false;
        $needsQuestion = $hints['needsQuestion'] ?? false;
        $needsDemographic = $hints['needsDemographic'] ?? false;
        $isMultiCycle = $hints['isMultiCycle'] ?? false;
        $query = strtolower($hints['query'] ?? '');
        
        // For multi-cycle comparisons, use specialized optimization
        if ($isMultiCycle) {
            return $this->optimizeForMultiCycleComparison($surveyData);
        }
        
        // Determine what answer types we need
        $needsComments = Str::contains($query, ['comment', 'feedback', 'response', 'said', 'mentioned']);
        $needsBenchmarks = Str::contains($query, ['benchmark', 'rating', 'rate', 'score']) && !$needsNps;
        $needsFiltering = $needsDemographic || Str::contains($query, ['grade', 'demographic', 'filter', 'group']);
        
        // Detect satisfaction/happiness queries - these need both NPS and comments
        $isSatisfactionQuery = Str::contains($query, [
            'happy', 'satisfied', 'satisfaction', 'doing well', 'doing good', 
            'how are we', 'how is', 'overall', 'feel', 'feeling', 'experience', 
            'opinion', 'feedback', 'popularity', 'popular'
        ]);
        
        // If query asks about a specific question, keep all answer types for that question
        // If demographic grouping, we need both NPS and filtering answers
        // If satisfaction query, we need both NPS and comments
        // If no specific needs detected, keep all answers but optimize structure
        // Otherwise, optimize based on detected needs
        $keepAllAnswers = $needsQuestion || (!$needsNps && !$needsComments && !$needsBenchmarks && !$needsFiltering);
        $keepNpsAndFiltering = $needsDemographic && $needsNps;
        $keepNpsAndComments = $isSatisfactionQuery && $needsNps;
        
        $optimized = [];
        
        foreach ($surveyData as $survey) {
            $optimizedSurvey = [
                'respondent_name' => $survey['respondent_name'] ?? null,
                'survey_cycle' => $survey['survey_cycle'] ?? null,
                'respondent_type' => $survey['respondent_type'] ?? null,
                'status' => $survey['status'] ?? null,
                'answers' => [],
            ];
            
            // Filter answers based on query needs
            foreach ($survey['answers'] ?? [] as $answer) {
                $answerType = $answer['type'] ?? '';
                
                // If query asks for a specific question, only include answers matching that question
                if ($needsQuestion) {
                    $questionText = $this->extractQuestionText($hints['query'] ?? '');
                    if ($questionText !== null) {
                        $answerQuestion = strtolower($answer['question'] ?? '');
                        $searchText = strtolower($questionText);
                        $answerQuestion = str_replace('[client_name]', '', $answerQuestion);
                        $searchText = str_replace('[client_name]', '', $searchText);
                        
                        // Skip answers that don't match the question
                        if (!Str::contains($answerQuestion, $searchText) && !Str::contains($searchText, $answerQuestion)) {
                            continue;
                        }
                    }
                }
                
                if ($keepAllAnswers) {
                    // Keep all answers but optimize structure
                    $optimizedSurvey['answers'][] = $this->optimizeAnswer($answer, $answerType);
                } elseif ($keepNpsAndFiltering) {
                    // For demographic grouping with NPS, keep both NPS and filtering answers
                    if ($answerType === 'nps' || $answerType === 'filtering') {
                        $optimizedSurvey['answers'][] = $this->optimizeAnswer($answer, $answerType);
                    }
                } elseif ($keepNpsAndComments) {
                    // For satisfaction queries, keep both NPS and comments
                    if ($answerType === 'nps' || $answerType === 'comment') {
                        $optimizedSurvey['answers'][] = $this->optimizeAnswer($answer, $answerType);
                    }
                } elseif ($needsNps && $answerType === 'nps') {
                    $optimizedSurvey['answers'][] = $this->optimizeAnswer($answer, 'nps');
                } elseif ($needsComments && $answerType === 'comment') {
                    $optimizedSurvey['answers'][] = $this->optimizeAnswer($answer, 'comment');
                } elseif ($needsBenchmarks && $answerType === 'benchmark') {
                    $optimizedSurvey['answers'][] = $this->optimizeAnswer($answer, 'benchmark');
                } elseif ($needsFiltering && $answerType === 'filtering') {
                    $optimizedSurvey['answers'][] = $this->optimizeAnswer($answer, 'filtering');
                }
            }
            
            // Only include if we have relevant answers
            if (!empty($optimizedSurvey['answers'])) {
                $optimized[] = $optimizedSurvey;
            }
        }
        
        return $optimized;
    }

    /**
     * Optimize individual answer structure - keep only essential fields
     */
    private function optimizeAnswer(array $answer, string $type): array
    {
        $optimized = [
            'type' => $type,
            'question' => $answer['question'] ?? null,
        ];
        
        switch ($type) {
            case 'nps':
                // Extract score
                $score = $answer['nps']['score'] ?? $answer['value'] ?? $answer['rating'] ?? null;
                if ($score !== null) {
                    $optimized['nps_score'] = is_string($score) ? (int)$score : $score;
                }
                break;
                
            case 'comment':
                $optimized['comment'] = $answer['comment'] ?? null;
                break;
                
            case 'benchmark':
                $optimized['rating'] = $answer['rating'] ?? null;
                break;
                
            case 'filtering':
                $optimized['value'] = $answer['value'] ?? null;
                break;
                
            default:
                // For unknown types, keep all fields but remove created_at
                $optimized = array_merge($optimized, $answer);
                unset($optimized['created_at']);
        }
        
        return $optimized;
    }

    /**
     * Optimize survey data for multi-cycle comparison
     * Extracts only essential fields: respondent_name, survey_cycle, and NPS score
     * This dramatically reduces context size (from ~6MB to ~200KB)
     */
    private function optimizeForMultiCycleComparison(array $surveyData): array
    {
        $optimized = [];
        
        foreach ($surveyData as $survey) {
            // Skip anonymous respondents for multi-cycle matching
            $respondentName = $survey['respondent_name'] ?? null;
            if ($respondentName === null || strtolower($respondentName) === 'anonymous') {
                continue;
            }
            
            // Extract NPS score from answers
            $npsScore = null;
            foreach ($survey['answers'] ?? [] as $answer) {
                if (($answer['type'] ?? '') === 'nps') {
                    // Try to get score from nps.score first, then value, then rating
                    $npsScore = $answer['nps']['score'] ?? $answer['value'] ?? $answer['rating'] ?? null;
                    
                    // Convert to integer if string
                    if (is_string($npsScore)) {
                        $npsScore = (int)$npsScore;
                    }
                    
                    if ($npsScore !== null && $npsScore >= 0 && $npsScore <= 10) {
                        break; // Found valid NPS score
                    }
                    $npsScore = null;
                }
            }
            
            // Only include if we have an NPS score
            if ($npsScore !== null) {
                $optimized[] = [
                    'respondent_name' => $respondentName,
                    'survey_cycle' => $survey['survey_cycle'] ?? null,
                    'respondent_type' => $survey['respondent_type'] ?? null,
                    'nps_score' => $npsScore,
                ];
            }
        }
        
        return $optimized;
    }

    /**
     * Get full KB data (for advanced queries)
     */
    public function getFullData(): array
    {
        return $this->loadData();
    }
}

