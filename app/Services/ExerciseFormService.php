<?php

namespace App\Services;

use App\Models\Exercise;
use App\Models\User;
use App\Services\ComponentBuilder as C;
use App\Services\ExerciseTypes\LogTypeTable;

class ExerciseFormService
{
    /**
     * Generate exercise form component for create/edit
     */
    public function generateExerciseForm(Exercise $exercise, User $user, string $action, string $method = 'POST'): array
    {
        $isEdit = $exercise->exists;
        $canCreateGlobal = $user->hasRole('Admin');
        
        $form = C::form('exercise-form', $isEdit ? 'Edit Exercise' : 'Create Exercise')
            ->formAction($action);
            
        // Add method override for PUT requests
        if ($method !== 'POST') {
            $form->hiddenField('_method', $method);
        }
        
        $form->textField(
            'name',
            'Exercise Name',
            old('name', $exercise->name ?? ''),
            'Enter the exercise name'
        );
        
        // Description field
        $form->textareaField(
            'description',
            'Description',
            old('description', $exercise->description ?? ''),
            'Optional description of the exercise'
        );
        
        // Log type field
        $form->selectField(
            'log_type',
            'Log Type',
            $this->getLogTypeOptions(),
            old('log_type', $exercise->exists ? ($exercise->log_type ?? '') : '')
        );
        
        // Show in feed checkbox
        $form->checkboxField(
            'show_in_feed',
            'Show in PR Feed',
            old('show_in_feed', $exercise->show_in_feed ?? false),
            'When enabled, PRs for this exercise will appear in the social feed'
        );
        
        // Submit button
        $form->submitButton($isEdit ? 'Update Exercise' : 'Create Exercise');
        
        // Add validation error messages
        $this->addValidationErrors($form);
        
        return $form->build();
    }
    
    /**
     * The log type options for the select field, in the table's order.
     *
     * @return list<array{value: string, label: string}>
     */
    private function getLogTypeOptions(): array
    {
        $options = [['value' => '', 'label' => 'Select Log Type']];

        foreach (LogTypeTable::logTypes() as $logType) {
            $options[] = ['value' => $logType, 'label' => LogTypeTable::labelFor($logType)];
        }

        return $options;
    }

    /**
     * Add validation error messages to the form
     */
    private function addValidationErrors($form): void
    {
        if ($errors = session('errors')) {
            if ($errors->has('name')) {
                $form->message('error', $errors->first('name'));
            }
            if ($errors->has('description')) {
                $form->message('error', $errors->first('description'));
            }
            if ($errors->has('log_type')) {
                $form->message('error', $errors->first('log_type'));
            }
            if ($errors->has('show_in_feed')) {
                $form->message('error', $errors->first('show_in_feed'));
            }
        }
    }
}