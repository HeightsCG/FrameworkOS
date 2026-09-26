-- Automations pick one scene line per run, verbatim, cycling through the list (AutomationPrompt::pick).
ALTER TABLE scheduler_rules ADD COLUMN scene_history TEXT NULL AFTER caption_text;
