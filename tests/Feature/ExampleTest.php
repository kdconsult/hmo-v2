<?php

test('landlord panel login page is accessible', function () {
    $response = $this->get('/landlord/login');

    $response->assertStatus(200);
});
