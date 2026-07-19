<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * =====================================================================================
 * REUSABLE TEMPLATE: isolated PHPUnit smoke test for a single Card subclass's hooks.
 *
 * Pattern: a card's hook methods (ResolutionStepAttackTriggers, ProcessTrigger, etc.)
 * call a handful of GLOBAL engine functions (AddLayer, Draw, DiscardRandom, ...) whose
 * real implementations live in CardLogic.php/CoreLogic.php and pull in the whole game
 * engine (gamestate, DB, session). Classes/Card.php and Classes/CardObjects/*.php have
 * NO require/include statements and call no engine functions at load time, so they can
 * be require_once'd directly WITHOUT ever loading the real engine — as long as we define
 * our OWN test-double versions of the global functions the target hooks call, guarded by
 * function_exists() (same convention as tests/bootstrap.php's mocks), BEFORE requiring
 * the card file. If a real engine file is ever accidentally required first elsewhere in
 * the suite, function_exists() makes the collision fail loudly (fatal "cannot redeclare
 * function") rather than silently running against the wrong implementation.
 *
 * TO COPY FOR A NEW CARD:
 *   1. Change the two require_once paths below to the new card's file, and swap
 *      `wrecking_ball_red` for the new card class throughout.
 *   2. Grep the new card's hook methods for which global functions they call; add or
 *      adjust stubs in the "Global engine function stubs" section — record the call args
 *      and, for anything the card's logic branches on, make the return value
 *      configurable via $GLOBALS['__cardHookTestStubs'].
 *   3. Write one test method per hook behavior, asserting against the recorded call args
 *      or a conditional outcome (e.g. "did X fire when the stub returned >= threshold,
 *      and NOT fire when it returned < threshold") — not just "was called".
 * =====================================================================================
 */

// ---- Global engine function stubs (recorded calls + controllable return values) ----
// Real implementations: CardLogic.php (AddLayer, DiscardRandom, ModifiedPowerValue,
// Intimidate), CoreLogic.php (Draw). Never required by this test file.

$GLOBALS['__cardHookTestCalls'] = [];
$GLOBALS['__cardHookTestStubs'] = [
    'DiscardRandom' => null,
    'ModifiedPowerValue' => 0,
];

if (!function_exists('AddLayer')) {
    function AddLayer($cardID, $player, $parameter, $target = "-", $additionalCosts = "-", $uniqueID = "-", $layerUID = "-", $skipOrdering = false) {
        $GLOBALS['__cardHookTestCalls'][] = ['AddLayer', [$cardID, $player, $parameter]];
    }
}

if (!function_exists('Draw')) {
    function Draw($player, $mainPhase = true, $fromCardEffect = true, $effectSource = "-", $num = 1) {
        $GLOBALS['__cardHookTestCalls'][] = ['Draw', [$player, $effectSource]];
    }
}

if (!function_exists('DiscardRandom')) {
    function DiscardRandom($player = "", $source = "", $effectController = "") {
        $GLOBALS['__cardHookTestCalls'][] = ['DiscardRandom', [$player, $source, $effectController]];
        return $GLOBALS['__cardHookTestStubs']['DiscardRandom'];
    }
}

if (!function_exists('ModifiedPowerValue')) {
    function ModifiedPowerValue($cardID, $player, $from, $source = "", $index = -1) {
        $GLOBALS['__cardHookTestCalls'][] = ['ModifiedPowerValue', [$cardID, $player, $from, $source]];
        return $GLOBALS['__cardHookTestStubs']['ModifiedPowerValue'];
    }
}

if (!function_exists('Intimidate')) {
    function Intimidate($player = "") {
        $GLOBALS['__cardHookTestCalls'][] = ['Intimidate', [$player]];
    }
}

require_once __DIR__ . '/../../Classes/Card.php';
require_once __DIR__ . '/../../Classes/CardObjects/RVDCards.php';

final class CardHookTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__cardHookTestCalls'] = [];
        $GLOBALS['__cardHookTestStubs'] = [
            'DiscardRandom' => 'fixture_discarded_card',
            'ModifiedPowerValue' => 0,
        ];
    }

    private function callsTo(string $function): array
    {
        return array_values(array_filter(
            $GLOBALS['__cardHookTestCalls'],
            fn($call) => $call[0] === $function
        ));
    }

    public function testResolutionStepAttackTriggersAddsTriggerLayer(): void
    {
        $card = new wrecking_ball_red('player1');
        $card->ResolutionStepAttackTriggers();

        $calls = $this->callsTo('AddLayer');
        $this->assertCount(1, $calls, 'AddLayer should be called exactly once');
        $this->assertSame(['TRIGGER', 'player1', 'wrecking_ball_red'], $calls[0][1]);
    }

    public function testProcessTriggerDrawsThenDiscardsRandomCard(): void
    {
        $card = new wrecking_ball_red('player1');
        $card->ProcessTrigger('uid-1');

        $drawCalls = $this->callsTo('Draw');
        $this->assertCount(1, $drawCalls);
        $this->assertSame(['player1', 'wrecking_ball_red'], $drawCalls[0][1]);

        $discardCalls = $this->callsTo('DiscardRandom');
        $this->assertCount(1, $discardCalls);
        $this->assertSame(['player1', 'wrecking_ball_red', 'player1'], $discardCalls[0][1]);

        // ModifiedPowerValue must be checked against the card DiscardRandom actually returned.
        $powerCalls = $this->callsTo('ModifiedPowerValue');
        $this->assertCount(1, $powerCalls);
        $this->assertSame(['fixture_discarded_card', 'player1', 'HAND', 'wrecking_ball_red'], $powerCalls[0][1]);
    }

    public function testProcessTriggerIntimidatesWhenDiscardedPowerIsSixOrGreater(): void
    {
        $GLOBALS['__cardHookTestStubs']['ModifiedPowerValue'] = 6;
        $card = new wrecking_ball_red('player1');

        $card->ProcessTrigger('uid-1');

        $this->assertCount(1, $this->callsTo('Intimidate'), 'Intimidate should fire when discarded power >= 6');
    }

    public function testProcessTriggerDoesNotIntimidateWhenDiscardedPowerIsBelowSix(): void
    {
        $GLOBALS['__cardHookTestStubs']['ModifiedPowerValue'] = 5;
        $card = new wrecking_ball_red('player1');

        $card->ProcessTrigger('uid-1');

        $this->assertCount(0, $this->callsTo('Intimidate'), 'Intimidate should NOT fire when discarded power < 6');
    }
}
