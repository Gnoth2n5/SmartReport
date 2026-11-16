# Skills Catalog

This workspace contains 39 project skills. Each skill's `SKILL.md` frontmatter
defines when it applies; this file only helps agents choose the smallest useful
set. Do not load every skill by default.

## Selection Order

1. For any `enterprise-hrm/` code change, load
   [`enterprise-hrm-architecture`](enterprise-hrm-architecture/SKILL.md) first.
2. For source comparison, ownership, extraction, or migration planning, add
   [`hrm-extraction`](hrm-extraction/SKILL.md).
3. Add only the companion skills required by the affected layer or risk.
4. Use OrangeHRM skills as behavior references. Adapt their guidance to Laravel;
   do not copy Doctrine, plugin, or non-router structure into the target.

## Target Task Router

| Task                                                | Load                                                                                                |
| --------------------------------------------------- | --------------------------------------------------------------------------------------------------- |
| Laravel domain, API, import, schema, or test change | `enterprise-hrm-architecture` plus the relevant companion below                                     |
| Domain/source ownership or rule extraction          | `hrm-extraction`                                                                                    |
| Vue SFC, composable, or frontend TypeScript         | `vue-best-practices`                                                                                |
| Vue route, guard, parameter, or lifecycle           | `vue-best-practices` + `vue-router-best-practices`                                                  |
| Migration or source mapping                         | `migrations`, adapted to Laravel migrations                                                         |
| Domain write workflow                               | `services`, adapted to Laravel Actions                                                              |
| API validation or response contract                 | `rest-endpoints` + `rest-validation` + `rest-serialization`, adapted to Form Requests and Resources |
| Role, ownership, or state authorization             | `authorization`; add `workflow` for transitions                                                     |
| Payroll, auth, employee PII, or documents           | `security-threat-model`; add `security-best-practices` for review                                   |
| PHP unit or feature test                            | `phpunit-skill`                                                                                     |
| Browser workflow or E2E verification                | `playwright-cli`                                                                                    |
| Detailed packets for small/local coding subagents   | `small-agent-task-compiler`                                                                         |

The target release gate uses Playwright. Keep `cypress-skill` and
`laravel-dusk-skill` dormant unless the project explicitly adopts those
frameworks.

## HRM And Target Architecture

| Skill                                                                 | Use                                                                             |
| --------------------------------------------------------------------- | ------------------------------------------------------------------------------- |
| [`enterprise-hrm-architecture`](enterprise-hrm-architecture/SKILL.md) | Mandatory domain-first Laravel + Vue ownership and boundaries for target code   |
| [`hrm-extraction`](hrm-extraction/SKILL.md)                           | Source inventory, canonical ownership, migration notes, and HRM rule extraction |
| [`vue-best-practices`](vue-best-practices/SKILL.md)                   | Vue 3 Composition API, TypeScript, SFCs, composables, and component design      |
| [`vue-router-best-practices`](vue-router-best-practices/SKILL.md)     | Vue Router routes, guards, parameters, and route-component lifecycle            |

## Target Verification And Security

| Skill                                                         | Use                                                                                     |
| ------------------------------------------------------------- | --------------------------------------------------------------------------------------- |
| [`phpunit-skill`](phpunit-skill/SKILL.md)                     | PHPUnit unit and feature tests for Laravel/PHP behavior                                 |
| [`playwright-cli`](playwright-cli/SKILL.md)                   | Browser interaction, Playwright tests, snapshots, traces, and UI verification           |
| [`cypress-skill`](cypress-skill/SKILL.md)                     | Cypress generation only if Cypress is explicitly adopted later                          |
| [`laravel-dusk-skill`](laravel-dusk-skill/SKILL.md)           | Laravel Dusk generation only if Dusk is explicitly adopted later                        |
| [`security-threat-model`](security-threat-model/SKILL.md)     | Repository-grounded threat modeling for auth, payroll, documents, PII, and integrations |
| [`security-best-practices`](security-best-practices/SKILL.md) | Security review of sensitive Laravel, PHP, JavaScript, and TypeScript changes           |

## OrangeHRM Runtime Reference

| Skill                                         | Use                                                                        |
| --------------------------------------------- | -------------------------------------------------------------------------- |
| [`compatibility`](compatibility/SKILL.md)     | Supported PHP, database, Node, browser, Docker, and CI versions            |
| [`dev-environment`](dev-environment/SKILL.md) | OrangeHRM Docker topology, commands, and troubleshooting                   |
| [`dependencies`](dependencies/SKILL.md)       | OrangeHRM Composer/Yarn manifests, lockfiles, and package workflow         |
| [`ecosystem`](ecosystem/SKILL.md)             | OrangeHRM repositories, distributions, documentation, and release channels |
| [`testing`](testing/SKILL.md)                 | OrangeHRM PHPUnit, fixtures, Jest, Cypress, and test-database lifecycle    |

## OrangeHRM Persistence Reference

| Skill                                               | Use                                                                         |
| --------------------------------------------------- | --------------------------------------------------------------------------- |
| [`doctrine-bootstrap`](doctrine-bootstrap/SKILL.md) | Doctrine EntityManager, entity discovery, caches, proxies, and DQL setup    |
| [`entities`](entities/SKILL.md)                     | Doctrine entity mappings, relations, lifecycle, decorators, and tree models |
| [`daos`](daos/SKILL.md)                             | OrangeHRM DAO, QueryBuilder, pagination, filters, and transaction patterns  |
| [`migrations`](migrations/SKILL.md)                 | OrangeHRM installer/upgrader migrations and schema helpers                  |

## OrangeHRM API Reference

| Skill                                               | Use                                                                      |
| --------------------------------------------------- | ------------------------------------------------------------------------ |
| [`rest-endpoints`](rest-endpoints/SKILL.md)         | REST route-to-endpoint lifecycle, endpoint types, parameters, and errors |
| [`rest-validation`](rest-validation/SKILL.md)       | Request validation rules and custom validators                           |
| [`rest-serialization`](rest-serialization/SKILL.md) | Models, normalization, resources, collections, and response metadata     |
| [`rest-openapi`](rest-openapi/SKILL.md)             | OpenAPI annotations and shared response components                       |

## OrangeHRM Auth And Security Reference

| Skill                                                 | Use                                                                |
| ----------------------------------------------------- | ------------------------------------------------------------------ |
| [`authorization`](authorization/SKILL.md)             | Role/data-group permissions, screen/API gates, and row-level scope |
| [`auth-providers`](auth-providers/SKILL.md)           | Local, LDAP, OAuth2, and OpenID Connect authentication providers   |
| [`security-primitives`](security-primitives/SKILL.md) | Encryption, keys, password hashing, and CSRF primitives            |

## OrangeHRM Frontend Reference

| Skill                                             | Use                                                                |
| ------------------------------------------------- | ------------------------------------------------------------------ |
| [`frontend-pages`](frontend-pages/SKILL.md)       | OrangeHRM mini-SPA page registration and OXD components            |
| [`frontend-data`](frontend-data/SKILL.md)         | API service, pagination, sorting, form, and validation composables |
| [`frontend-platform`](frontend-platform/SKILL.md) | i18n, ACL, toaster, loader, dates, and navigation plugins          |

## OrangeHRM Backend Reference

| Skill                                           | Use                                                                               |
| ----------------------------------------------- | --------------------------------------------------------------------------------- |
| [`services`](services/SKILL.md)                 | Service/DAO boundaries, traits, registration, and business logic ownership        |
| [`events`](events/SKILL.md)                     | Domain and Symfony events, subscribers, registration, and priorities              |
| [`console-commands`](console-commands/SKILL.md) | Production and developer console entry points and command registration            |
| [`config`](config/SKILL.md)                     | Runtime settings, typed accessors, migration config, and config-vs-entity choices |
| [`helpers`](helpers/SKILL.md)                   | Shared helper services and traits across the OrangeHRM framework                  |

## OrangeHRM Cross-Cutting Reference

| Skill                                       | Use                                                                         |
| ------------------------------------------- | --------------------------------------------------------------------------- |
| [`mail`](mail/SKILL.md)                     | Queued email, encrypted configuration, templates, and event-driven delivery |
| [`scheduled-jobs`](scheduled-jobs/SKILL.md) | Scheduler registration, cron runner, tasks, timezones, and audit logs       |
| [`workflow`](workflow/SKILL.md)             | Workflow state transitions and state-specific authorization                 |
| [`menus`](menus/SKILL.md)                   | Menu hierarchy, permissions, rendering, and migration seeding               |

## Authoring Skills

This README catalogs skills; each `SKILL.md` owns its instructions. When adding,
renaming, or removing a skill, keep valid `name` and `description` frontmatter,
keep code-derived facts in the owning skill, and update this catalog in the same
change.

## Coordination Skills

| Skill                                                             | Use                                                                                                                                                                         |
| ----------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| [`small-agent-task-compiler`](small-agent-task-compiler/SKILL.md) | Compile invariant-traceable packets for small/local coding agents and validate coordinator-owned execution receipts and phase-closure evidence without dispatching workers. |
