#!/bin/sh
set -eu

mkdir -p \
    /tmp/uih-test-storage/framework/cache/data \
    /tmp/uih-test-storage/framework/sessions \
    /tmp/uih-test-storage/framework/testing \
    /tmp/uih-test-storage/framework/views \
    /tmp/uih-test-storage/logs

verify_artisan_database_target() {
    [ "${1:-}" = "php" ] && [ "${2:-}" = "artisan" ] || return 0

    shift 2
    artisan_command=

    while [ "$#" -gt 0 ]; do
        case "$1" in
            --env|-e|--app-env)
                shift
                [ "$#" -gt 0 ] && shift
                ;;
            --env=*|--app-env=*)
                shift
                ;;
            --)
                shift
                artisan_command="${1:-}"
                break
                ;;
            -* )
                shift
                ;;
            *)
                artisan_command="$1"
                break
                ;;
        esac
    done

    case "$artisan_command" in
        db:seed|db:wipe|migrate|migrate:*|schema:dump)
            php artisan uih:verify-test-database
            ;;
    esac
}

verify_artisan_database_target "$@"

exec "$@"
