<?php
use App\Service\FormTemplate;

it('FormTemplate uses {formName} placeholder', function () {
    $out = FormTemplate::renderTaskTitle(
        '{formName} — new lead',
        ['id' => 1, 'title' => 'Contact'],
        ['id' => 42],
        []
    );
    assert_eq('Contact — new lead', $out);
});

it('FormTemplate substitutes field key placeholders', function () {
    $out = FormTemplate::renderTaskTitle(
        '{formName} — new lead from {name}',
        ['id' => 1, 'title' => 'Contact'],
        ['id' => 42],
        ['name' => 'Alice', 'email' => 'a@b.c']
    );
    assert_eq('Contact — new lead from Alice', $out);
});

it('FormTemplate joins array values with commas', function () {
    $out = FormTemplate::renderTaskTitle(
        'Interested in: {options}',
        ['title' => 'Survey'],
        ['id' => 7],
        ['options' => ['Plan A', 'Plan B']]
    );
    assert_eq('Interested in: Plan A, Plan B', $out);
});

it('FormTemplate leaves unknown placeholders intact', function () {
    $out = FormTemplate::renderTaskTitle(
        '{formName} — {missing_field}',
        ['title' => 'X'],
        ['id' => 1],
        []
    );
    assert_eq('X — {missing_field}', $out);
});

it('FormTemplate falls back when template empty', function () {
    $out = FormTemplate::renderTaskTitle('', ['title' => 'X'], ['id' => 7], []);
    assert_eq('X #7', $out);
});

it('FormTemplate falls back when template null', function () {
    $out = FormTemplate::renderTaskTitle(null, ['title' => 'X'], ['id' => 9], []);
    assert_eq('X #9', $out);
});

it('FormTemplate supports {submission.id}', function () {
    $out = FormTemplate::renderTaskTitle(
        '{formName} — #{submission.id}',
        ['title' => 'Q'],
        ['id' => 12],
        []
    );
    assert_eq('Q — #12', $out);
});

it('FormTemplate caps length at maxLen', function () {
    $out = FormTemplate::renderTaskTitle(
        str_repeat('a', 500),
        ['title' => 'X'],
        ['id' => 1],
        [],
        50
    );
    assert_eq(50, mb_strlen($out));
});

it('FormTemplate falls back when result is whitespace-only', function () {
    $out = FormTemplate::renderTaskTitle(
        '   ',
        ['title' => 'Y'],
        ['id' => 3],
        []
    );
    assert_eq('Y #3', $out);
});
