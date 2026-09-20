-- Forms: two new question types, 'email' and 'phone' (validated on the server and
-- in the browser). Extends the form_questions.type enum; existing types and rows
-- are untouched. Safe to run more than once.
-- Run against centryk_core:
--   C:/xampp/mysql/bin/mysql.exe -u root centryk_core < database/add_form_email_phone_types.sql

ALTER TABLE `form_questions`
    MODIFY COLUMN `type` ENUM('short_text','long_text','single_choice','multiple_choice','dropdown','rating','yes_no','number','date','section','email','phone') NOT NULL;
