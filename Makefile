.PHONY: lint stan qa format format-check composer-validate composer-audit platform-check docker-config-check ci-check smoke smoke-dry-run smoke-web smoke-sync smoke-sync-record

lint:
	composer run lint

stan:
	composer run stan

qa:
	composer run qa

format:
	composer run format

format-check:
	composer run format:check

composer-validate:
	composer validate --strict

composer-audit:
	composer audit

platform-check:
	composer check-platform-reqs

docker-config-check:
	docker compose config -q

ci-check: composer-validate composer-audit platform-check docker-config-check qa

smoke:
	bash scripts/smoke/run.sh

smoke-dry-run:
	bash scripts/smoke/run.sh --dry-run

smoke-web:
	bash scripts/smoke/run.sh --web-only

smoke-sync:
	bash scripts/smoke/run.sh --sync-only

smoke-sync-record:
	bash scripts/smoke/run.sh --sync-only --record-sync
