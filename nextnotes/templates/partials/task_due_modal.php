<?php
/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// abgeleitet von src/task_due_modal.php @ 7e2e0b6aa501ba95ac1e9789fd2bb477c281a001
// Fälligkeits-Modal der Aufgaben (Hauptansicht über modals.php, tasks.php); ohne E-Mail-Schalter (Erinnerung über NC-Benachrichtigung).
/** @var \OCA\NextNotes\Service\View\PageTranslator $tr */
?>
<div
    id="taskDueModal"
    class="modal"
    data-email-available="0"
    data-txt-date="<?php p($tr->t('reminder.modal.date_placeholder', [], 'Date')); ?>"
    data-txt-time="<?php p($tr->t('reminder.modal.time_placeholder', [], 'Time')); ?>"
    data-txt-no-date="<?php p($tr->t('reminder.modal.no_date_configured', [], 'No date configured')); ?>"
    data-txt-remove-time="<?php p($tr->t('tasklist.due_remove_time', [], 'Remove time')); ?>"
>
    <div class="modal-content">
        <h3><?php p($tr->t('tasklist.due_date', [], 'Due date')); ?></h3>
        <div class="reminder-form">
            <div class="reminder-input-row">
                <button type="button" id="taskDueDateBtn" class="reminder-datetime-part">
                    <i class="lucide lucide-calendar-alt"></i>
                    <span id="taskDueDateBtnLabel"><?php p($tr->t('reminder.modal.date_placeholder', [], 'Date')); ?></span>
                </button>
                <button type="button" id="taskDueTimeBtn" class="reminder-datetime-part">
                    <i class="lucide lucide-clock"></i>
                    <span id="taskDueTimeBtnLabel"><?php p($tr->t('reminder.modal.time_placeholder', [], 'Time')); ?></span>
                </button>
            </div>
            <div class="reminder-repeat-row" id="taskDueRepeatRow">
                <label class="reminder-repeat-label" for="taskDueRepeatSelect">
                    <i class="lucide lucide-repeat"></i>
                    <?php p($tr->t('reminder.modal.repeat_label', [], 'Repeat')); ?>
                </label>
                <select id="taskDueRepeatSelect" class="reminder-repeat-select">
                    <option value=""><?php p($tr->t('reminder.modal.repeat_none', [], 'Does not repeat')); ?></option>
                    <option value="1h"><?php p($tr->t('reminder.modal.repeat_hourly', [], 'Hourly')); ?></option>
                    <option value="1d"><?php p($tr->t('reminder.modal.repeat_daily', [], 'Daily')); ?></option>
                    <option value="1w"><?php p($tr->t('reminder.modal.repeat_weekly', [], 'Weekly')); ?></option>
                    <option value="1m"><?php p($tr->t('reminder.modal.repeat_monthly', [], 'Monthly')); ?></option>
                    <option value="1y"><?php p($tr->t('reminder.modal.repeat_yearly', [], 'Yearly')); ?></option>
                    <option value="custom"><?php p($tr->t('reminder.modal.repeat_custom', [], 'Custom...')); ?></option>
                </select>
            </div>
            <div class="reminder-repeat-custom initially-hidden" id="taskDueRepeatCustom">
                <span class="reminder-repeat-every"><?php p($tr->t('reminder.modal.repeat_every', [], 'Every')); ?></span>
                <input type="number" id="taskDueRepeatInterval" class="reminder-repeat-interval" min="1" max="999" value="1">
                <select id="taskDueRepeatUnit" class="reminder-repeat-unit">
                    <option value="i"><?php p($tr->t('reminder.modal.repeat_minutes', [], 'minute(s)')); ?></option>
                    <option value="h"><?php p($tr->t('reminder.modal.repeat_hours', [], 'hour(s)')); ?></option>
                    <option value="d"><?php p($tr->t('reminder.modal.repeat_days', [], 'day(s)')); ?></option>
                    <option value="w"><?php p($tr->t('reminder.modal.repeat_weeks', [], 'week(s)')); ?></option>
                    <option value="m"><?php p($tr->t('reminder.modal.repeat_months', [], 'month(s)')); ?></option>
                    <option value="y"><?php p($tr->t('reminder.modal.repeat_years', [], 'year(s)')); ?></option>
                </select>
            </div>
            <div class="reminder-repeat-hint initially-hidden" id="taskDueRepeatHint">
                <?php p($tr->t('reminder.modal.task_repeat_hint', [], 'The next occurrence is added to the list when you complete the task. Dismissing the notification moves the task to its next date.')); ?>
            </div>
            <div class="reminder-email-option" id="taskDueRemindOption">
                <label class="reminder-email-label" for="taskDueRemindInput">
                    <span class="reminder-email-copy">
                        <span class="reminder-email-title">
                            <i class="lucide lucide-bell"></i>
                            <?php p($tr->t('tasklist.remind_me', [], 'Remind me')); ?>
                        </span>
                        <span class="reminder-email-hint">
                            <?php p($tr->t('tasklist.remind_me_hint', [], 'A notification fires at the due date and time.')); ?>
                        </span>
                    </span>
                    <span class="toggle-switch reminder-email-switch">
                        <input type="checkbox" id="taskDueRemindInput">
                        <span class="toggle-slider"></span>
                    </span>
                </label>
            </div>
            <div class="reminder-email-option initially-hidden" id="taskDueEmailOption">
                <label class="reminder-email-label" for="taskDueEmailInput">
                    <span class="reminder-email-copy">
                        <span class="reminder-email-title">
                            <i class="lucide lucide-mail"></i>
                            <?php p($tr->t('reminder.modal.email_toggle', [], 'Send me an email')); ?>
                        </span>
                        <span class="reminder-email-hint">
                            
                        </span>
                    </span>
                    <span class="toggle-switch reminder-email-switch">
                        <input type="checkbox" id="taskDueEmailInput">
                        <span class="toggle-slider"></span>
                    </span>
                </label>
            </div>
            <div class="reminder-current-info is-empty" id="taskDueCurrentInfo">
                <i class="lucide lucide-calendar-alt"></i>
                <span id="taskDueCurrentDate"></span>
            </div>
        </div>
        <div class="modal-buttons reminder-modal-actions">
            <button type="button" class="btn-danger initially-hidden reminder-modal-action" id="taskDueRemoveBtn"><?php p($tr->t('tasklist.due_remove', [], 'Remove due date')); ?></button>
            <button type="button" class="btn-cancel reminder-modal-action" data-action="close-modal" data-modal="taskDueModal"><?php p($tr->t('common.cancel')); ?></button>
            <button type="button" class="btn-primary reminder-modal-action" id="taskDueSaveBtn"><?php p($tr->t('common.save')); ?></button>
        </div>
    </div>
</div>
