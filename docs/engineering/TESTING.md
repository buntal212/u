# Testing

Run tests only in the independent Compose project `uih-test`. Its `test-db` is MySQL 8.0.40 with `/var/lib/mysql` mounted as tmpfs, no published port, no persistent volume, and test-only credentials. The runner receives its environment directly from `compose.test.yaml`; it does not use the development Compose environment. Runner `/tmp` is also tmpfs, Laravel storage points inside that temporary filesystem, and logs go to stderr so tests do not write into development storage. The MySQL health check gates runner startup.

## Test workflow

```bash
docker compose -p uih-test -f compose.test.yaml build test-runner
docker compose -p uih-test -f compose.test.yaml up -d --wait test-db
docker compose -p uih-test -f compose.test.yaml run --rm -T test-runner php artisan uih:verify-test-database
docker compose -p uih-test -f compose.test.yaml run --rm -T test-runner php artisan test --filter=HealthEndpointTest
docker compose -p uih-test -f compose.test.yaml run --rm -T test-runner php artisan test
docker compose -p uih-test -f compose.test.yaml run --rm -T test-runner vendor/bin/pint --test
docker compose -p uih-test -f compose.test.yaml run --rm -T test-runner composer validate --strict
docker compose -p uih-test -f compose.test.yaml down --remove-orphans
```

`uih:verify-test-database` checks `APP_ENV`, the default connection, driver, exact test service hostname, port, database, test username and password, empty `DB_URL` and `DB_SOCKET`, before querying the connected server identity, MySQL 8.0.40, and InnoDB. PHPUnit forces the test settings. The test case repeats this check before traits can run migrations, and the runner entrypoint checks it before direct Artisan migration, seed, and wipe commands. Unsafe configurations are rejected before connecting to a database.

The disposable test database is recreated when the Compose project is removed. Teardown is scoped by project name and Compose file; it does not remove volumes. No test command uses the development project. Keep the development database untouched while running the test suite.
