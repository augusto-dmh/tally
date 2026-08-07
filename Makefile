# Verification gates wrap Composer scripts (the underlying contract).
# Run against the Compose stack: `docker compose up -d --wait` first.
#
#   make gate-quick        unit suite + static analysis
#   make gate-integration  feature + integration suites (needs MySQL)
#   make gate-full         full test suite + static analysis

COMPOSE ?= docker compose
SERVICE ?= hyperf-skeleton
RUN ?= $(COMPOSE) exec -T -u 1000:1000 $(SERVICE)

.PHONY: gate-quick gate-integration gate-full

gate-quick:
	$(RUN) composer test -- --testsuite unit
	$(RUN) composer analyse

gate-integration:
	$(RUN) composer test -- --testsuite feature
	$(RUN) composer test -- --testsuite integration

gate-full:
	$(RUN) composer test
	$(RUN) composer analyse
