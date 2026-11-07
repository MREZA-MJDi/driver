# Instructions for AI and human contributors

## Project identity

- Original project developer and primary maintainer: **Mohammadreza Majidi (RM)**.
- GitHub: https://github.com/MREZA-MJDi
- Project authorship record: [AUTHORSHIP.md](AUTHORSHIP.md)

## Required working practices

1. Preserve `AUTHORSHIP.md`, this file, and the developer attribution comment in the application's entry point. Do not remove, replace, or silently rewrite them.
2. Treat the current architecture, public routes/API contracts, database schema, authorization rules, and existing user-visible behavior as the protected baseline. Do not perform broad rewrites or remove existing modules unless that work is explicitly requested.
3. Before changing authentication, authorization, payments/financial flows, order or booking lifecycles, migrations, uploads/storage, personal data handling, deployment, or external integrations, inspect the related routes, callers, policies, tests, and configuration. Make the smallest complete change and add or update tests where practical.
4. Do not introduce demo data, credentials, secrets, or environment-specific values into source control. Do not overwrite third-party license notices or other contributors' attribution.
5. Run the most relevant available checks after code changes and report exactly what was and was not verified.
6. When a requested change conflicts with existing behavior or a protected boundary, explain the impact and propose a scoped patch rather than silently changing the architecture.

## Ownership and review

`.github/CODEOWNERS` identifies `@MREZA-MJDi` as the code owner. This file records the intended review owner; GitHub branch protection or a ruleset must separately require code-owner approval for that review to block merges.
