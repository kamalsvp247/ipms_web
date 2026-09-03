---
name: Worker deletion cascades to account unassignment
description: When a worker (agent slot) is deleted, all accounts assigned to it are automatically unassigned
type: project
originSessionId: fc7e7495-dbf1-438d-a002-ade47031d05e
---
When a worker (agent slot) is deleted in `/bot-control`, the accounts assigned to that worker must have their `agent_slot_id` set to null (unassigned).

**Implementation note:** This cascading behavior should be enforced in the AgentSlot deletion logic (likely in a model event or controller) to ensure referential integrity.
