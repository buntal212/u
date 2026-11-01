# AI agent workflow

Every task follows:

**DISCOVER → PLAN → IMPLEMENT → TEST → REVIEW → REPORT**

## DISCOVER

- Confirm the UIH repository root, branch, Git status, and any staged or unstaged user changes before edits.
- Read `AGENTS.md`, the relevant engineering document, nearby tests, and the API contract being changed.
- Find skills in `.agents/skills/` first. Read the matching `SKILL.md` and any referenced rules: use `laravel-best-practices` for Laravel HTTP and application structure, `laravel-database-optimization` for database work, and `testing-best-practices` for PHPUnit coverage and review.
- Treat sibling repositories as read-only engineering references. Do not copy their business rules, credentials, or data.

## PLAN

For non-trivial work, state the affected contract, authorization boundary, database effects, expected files, applicable skills, focused and regression tests, and acceptance evidence. Identify unresolved decisions instead of inventing business policy.

## IMPLEMENT

- Stay within the requested UIH scope and preserve existing user changes.
- Follow [backend conventions](BACKEND_CONVENTIONS.md). Keep controllers thin, validate through Form Requests, put meaningful operations in Actions, and explicitly shape success responses with Resources.
- Do not add business functionality, speculative tables, generic service/repository layers, or infrastructure outside the task.

## TEST

- Run all PHP, Composer, Artisan, PHPUnit, and Pint commands in Docker. Never fall back to host PHP or Composer.
- Use the separate `uih-test` Compose project and its disposable MySQL service. Run the database preflight before migrations; never run tests against `uih-dev` or production.
- Run the focused tests first, then the full PHPUnit suite, Pint, and `composer validate --strict` when PHP or dependency files change.
- Report the actual PHPUnit test/assertion counts and each command result as PASS, FAIL, BLOCKED, or NOT_RUN.

## REVIEW

- Review the full diff for contract behavior, error shape, production guards, database safety, accidental secrets, unrelated paths, and whitespace introduced by the task.
- Stage only files changed for this task. Confirm the staged diff excludes user changes before committing.

## REPORT

Summarize implementation, verification evidence, Git status, and remaining gaps. Never claim an unrun check passed or commit an unverified completion. Commit only when the user explicitly authorizes it; never push without explicit authorization.
