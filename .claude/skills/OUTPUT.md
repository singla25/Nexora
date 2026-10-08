# Skill output convention

Skills imported from hiteshbandhu/skills-i-use write artifacts to `{SKILL_OUTPUT_DIR}/<skill-name>/`.
In this repo `SKILL_OUTPUT_DIR` is `./skill-outputs/` (git-ignored; never commit it).

| Skill | Output |
| --- | --- |
| raise-pr | optional run log |
| verify-like-a-user | `verify-YYYY-MM-DD-*.md` |

Note: `verify-like-a-user` references upstream files (`ui-ux`, `ship-check`) that do not exist here, and its examples lean React/Next.js; use its method (measure, break it, re-measure) and the generic patterns.
