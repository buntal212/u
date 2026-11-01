# UIH backend instructions

- Work only in the requested UIH scope. Preserve user changes and do not modify sibling or parent repositories.
- Follow **DISCOVER → PLAN → IMPLEMENT → TEST → REVIEW → REPORT**; details are in [`docs/engineering/AGENT_WORKFLOW.md`](docs/engineering/AGENT_WORKFLOW.md).
- Read matching project-local `.agents/skills/<skill>/SKILL.md` guidance first. Use `laravel-best-practices` for Laravel structure, HTTP, validation, and resources; `laravel-database-optimization` for database, migration, query, and transaction work; and `testing-best-practices` for PHPUnit design and review.
- Use Laravel 13, PHP 8.3, and MySQL 8.0.40 with InnoDB. Do not add a dependency or abstraction without a demonstrated need.
- Run PHP, Composer, Artisan, PHPUnit, and Pint only inside Docker. Use `uih-dev` for development and `uih-test` for tests.
- Tests must use only the disposable `uih-test` database. Keep the test database preflight and safety checks intact; never run test migrations against development or production.
- Keep API controllers thin. Use a Form Request for input validation, an Action for a meaningful application operation, and an API Resource for an explicit success response. See [`BACKEND_CONVENTIONS.md`](docs/engineering/BACKEND_CONVENTIONS.md).
- After PHP changes, run focused PHPUnit tests, the complete suite, Pint, and `composer validate --strict` as relevant. Report actual counts and command results; never invent evidence.
- Review the complete diff for scope, API contracts, secrets, and generated files. Stage only files changed for the authorized task. Commit only when explicitly authorized, and never push without explicit authorization.
