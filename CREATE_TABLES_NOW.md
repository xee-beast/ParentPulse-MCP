# ⚠️ CRITICAL: You MUST Create Tables First!

## The Problem
**The tables don't exist in Supabase.** That's why PostgREST can't find them.

## ✅ ACTION REQUIRED: Create Tables in Supabase

### Step 1: Open Supabase SQL Editor
1. Go to: https://supabase.com/dashboard
2. Select your project: `pseurkoblxdiqcnsvmtk`
3. Click **"SQL Editor"** (left sidebar)
4. Click **"New Query"**

### Step 2: Copy & Paste the Schema
1. Open file: `database/supabase_schema.sql`
2. **Select ALL** (Cmd/Ctrl + A)
3. **Copy** (Cmd/Ctrl + C)
4. **Paste** into Supabase SQL Editor
5. Click **"Run"** button (or press Cmd/Ctrl + Enter)

### Step 3: Check for Errors
- Look for **"Success"** message
- If you see errors, copy them and share

### Step 4: Verify Tables Exist
**In Supabase Table Editor:**
1. Click **"Table Editor"** (left sidebar)
2. You should see these tables:
   - ✅ `kb_tenants`
   - ✅ `kb_admins`
   - ✅ `kb_parents`
   - ✅ `kb_students`
   - ✅ `kb_employees`
   - ✅ `kb_survey_cycles`
   - ✅ `kb_survey_data`

**If you DON'T see them, the SQL didn't run. Try again.**

### Step 5: Run Verification Query
**In Supabase SQL Editor, run:**
```sql
SELECT table_name 
FROM information_schema.tables 
WHERE table_schema = 'public' 
AND table_name LIKE 'kb_%'
ORDER BY table_name;
```

**Expected:** Should return 7 rows (one for each table)

**If it returns 0 rows:** Tables weren't created. Check SQL Editor for errors.

### Step 6: After Tables Exist
1. Wait 1-2 minutes for schema cache to refresh
2. Or go to **Settings → API → Reload Schema Cache**
3. Run: `php artisan kb:test-supabase`
4. All tables should show ✅

---

## Quick Checklist
- [ ] Opened Supabase SQL Editor
- [ ] Copied entire `database/supabase_schema.sql` file
- [ ] Pasted into SQL Editor
- [ ] Clicked "Run"
- [ ] Saw "Success" message
- [ ] Verified tables in Table Editor (7 tables visible)
- [ ] Ran verification query (returns 7 rows)
- [ ] Refreshed schema cache OR waited 2 minutes

---

## If Tables Still Don't Exist

**Check SQL Editor for errors:**
- Look at the bottom of SQL Editor
- Any red error messages?
- Copy and share them

**Common issues:**
- SQL syntax errors
- Permission errors
- Wrong database selected

**Try running just the first table:**
```sql
CREATE TABLE IF NOT EXISTS kb_tenants (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id TEXT NOT NULL UNIQUE,
    name TEXT,
    school_type TEXT,
    domain TEXT,
    modules JSONB DEFAULT '[]'::jsonb,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);
```

If this works, then run the rest of the schema.

