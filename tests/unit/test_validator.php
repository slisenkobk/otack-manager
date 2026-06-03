<?php
declare(strict_types=1);

use App\Http\Validator;
use App\Http\ValidationException;

it('required catches missing field', function () {
    $v = Validator::for([])->required('name');
    assert_true($v->fails());
    assert_eq('required', $v->errors()['name']);
});

it('required catches empty string and whitespace-only', function () {
    $v1 = Validator::for(['name' => ''])->required('name');
    $v2 = Validator::for(['name' => "   \t  "])->required('name');
    assert_eq('required', $v1->errors()['name']);
    assert_eq('required', $v2->errors()['name']);
});

it('required passes with a real value', function () {
    $v = Validator::for(['name' => 'Alice'])->required('name');
    assert_true($v->passes());
});

it('email rejects malformed addresses', function () {
    $v = Validator::for(['e' => 'not-an-email'])->required('e')->email('e');
    assert_eq('invalid_email', $v->errors()['e']);
});

it('email accepts valid addresses', function () {
    $v = Validator::for(['e' => 'a@b.co'])->required('e')->email('e');
    assert_true($v->passes());
});

it('minLength catches short input and accepts boundary', function () {
    $short = Validator::for(['p' => '1234567'])->minLength('p', 8);
    $exact = Validator::for(['p' => '12345678'])->minLength('p', 8);
    assert_eq('too_short', $short->errors()['p']);
    assert_true($exact->passes());
});

it('maxLength catches long input and accepts boundary', function () {
    $long  = Validator::for(['x' => 'abcdef'])->maxLength('x', 5);
    $exact = Validator::for(['x' => 'abcde'])->maxLength('x', 5);
    assert_eq('too_long', $long->errors()['x']);
    assert_true($exact->passes());
});

it('in rejects out-of-set value', function () {
    $v = Validator::for(['role' => 'wizard'])->in('role', ['admin', 'manager', 'employee']);
    assert_eq('invalid_choice', $v->errors()['role']);
});

it('in accepts in-set value', function () {
    $v = Validator::for(['role' => 'admin'])->in('role', ['admin', 'manager', 'employee']);
    assert_true($v->passes());
});

it('captures only the FIRST error per field across chained rules', function () {
    // Two rules that both fail; the second should NOT overwrite the first.
    $v = Validator::for(['e' => 'nope'])->required('e')->email('e')->minLength('e', 100);
    assert_eq('invalid_email', $v->errors()['e']); // required passed, email fired first
    assert_eq(1, count($v->errors()));
});

it('passes/fails reflect error state', function () {
    $ok  = Validator::for(['n' => 'x'])->required('n');
    $bad = Validator::for(['n' => ''])->required('n');
    assert_true($ok->passes());
    assert_true(!$ok->fails());
    assert_true($bad->fails());
    assert_true(!$bad->passes());
});

it('clean returns trimmed data on success', function () {
    $clean = Validator::for(['name' => '  Alice  ', 'age' => 30])
        ->required('name')
        ->clean();
    assert_eq('Alice', $clean['name']);
    assert_eq(30, $clean['age']);
});

it('clean throws ValidationException with fields on failure', function () {
    $threw = false;
    try {
        Validator::for(['email' => ''])->required('email')->email('email')->clean();
    } catch (ValidationException $e) {
        $threw = true;
        assert_eq('required', $e->fields['email']);
        assert_eq('validation_failed', $e->getMessage());
    }
    assert_true($threw, 'expected ValidationException');
});
