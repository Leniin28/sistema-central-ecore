<?php

test('public registration is unavailable', function () {
    $this->get('/register')
        ->assertNotFound();

    $this->post('/register', [
        'name' => 'John Doe',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertNotFound();
});
