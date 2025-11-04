# CRITICAL: Tables Not Found - Troubleshooting

## The Problem
PostgREST can't see your tables, which means either:
1. **Tables weren't created** in Supabase
2. **Schema cache hasn't refreshed**
3. **Using wrong API key** (anon key might have restrictions)

## Solution: Use Service Role Key

The **Service Role Key** bypasses RLS and has full access. This is safer for migrations.

### Step 1: Get Your Service Role Key
1. Go to Supabase Dashboard
2. **Settings → API**
3. Find **"service_role"** key (NOT the anon key)
4. Copy it

### Step 2: Add to .env
```env
SUPABASE_SERVICE_ROLE_KEY=your-service-role-key-here
```

### Step 3: Test Again
```bash
php artisan kb:test-supabase
```

### Step 4: Run Migration
```bash
php artisan kb:migrate-supabase ab619904-2bb1-499e-82d1-31dfa1ad23f2
```

---

## Alternative: Verify Tables Were Created

If you think tables were created, check:

1. **Supabase Table Editor** - Do you see the tables?
   - If NO → Tables weren't created, run the SQL
   - If YES → Continue below

2. **Check SQL Editor History**
   - Look for your schema SQL execution
   - Check for any errors

3. **Run Verification Query**
   In Supabase SQL Editor, run:
   ```sql
   SELECT table_name 
   FROM information_schema.tables 
   WHERE table_schema = 'public' 
   AND table_name LIKE 'kb_%'
   ORDER BY table_name;
   ```
   
   Should return 7 rows. If empty, tables don't exist.

---

## If Tables Exist But Still Not Found

1. **Refresh Schema Cache**
   - Settings → API → Reload Schema Cache
   - Or wait 5 minutes

2. **Use Service Role Key** (recommended)
   - Service role bypasses RLS
   - More reliable for migrations

3. **Check Table Permissions**
   Make sure you ran the GRANT statements in the schema SQL

---

## Quick Fix: Use Service Role Key

**This is the fastest solution** - service role key bypasses all RLS and schema cache issues.

1. Add `SUPABASE_SERVICE_ROLE_KEY` to `.env`
2. The migration service will automatically use it
3. Run migration again

