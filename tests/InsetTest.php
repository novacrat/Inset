<?php
namespace Novacrat\Inset;

require '../src/Inset.php';

//declare(strict_types=1);
use PHPUnit\Framework\TestCase;


final class InsetTest extends TestCase
{
    public function testComments()
    {
        $in = new Inset('test_Comments.html');
        $out = $in->render();
        $this->assertSame("ab  cd\n{#", $out); 
    }

    public function testVars()
    {
        $in = new Inset('test_Vars.html');
        $in->setVar('foo', 'BAR');
        $in->setVar('more', true);
        $in->setVar('A', 5.5);
        $in->setVar('B', array(1,2));
        $in->setVar('C', array(3,4));
        $in->setVar('D', array('bar'=> 6, 'foo'=> 7));
        $out = $in->render();
        $this->assertSame("a1 A B {{\"C}}\nb BAR \nc5.547", $out); 
    }

    public function testIf() {
        $in = new Inset('test_If.html');
        $in->setVar('Two', true);
        $in->setVar('Three', false);
        $in->setVar('Four', '');
        $in->setVar('Five', 0);
        $in->setVar('Six', 7);
        $in->setVar('Seven', 8);
        $out = $in->render();
        $this->assertSame("{[if 1]}a{[endif]}Onebdgjn", $out);    
    }
    
    public function testFor() {
        $in = new Inset('test_For.html');
        $in->setVar('One', [1, 2, 3]);
        $in->setVar('Two', array([1, 2], ['a', 'b'], 'c'));
        $in->setVar('Three', 'A');
        $out = $in->render();
        $this->assertSame("12312abcA", $out);    
    }

    public function testBlocks() {
        $in = new Inset('test_Blocks.html');
        $in->setVar('One', '1');
        $in->setVar('Two', '2');
        $in->setVar('Three', array(7, 8, 9));
        $in->setFiller('SecondSlot', 'Q');
        $out = $in->render();
        $this->assertSame("1=189EMBED127Q", $out);
        $this->assertTrue($in->hasSlot('FirstSlot'));
    }
    
    public function testFilters() {
        $in = new Inset('test_Filters.html');
        $in->setVar('String', 'abc');
        $in->setVar('Text', 'def ghi');
        $in->setVar('Html', '<b>tag</b>');
        $in->setVar('Number', 2);
        $in->setVar('Array', ['A'=> 'a', 'B'=>'b', 3]);
        //$in->setVar('Date', '11/11/2011');
        $out = $in->render();
        $this->assertSame("Def GhiAbcABCabctag&lt;b&gt;tag&lt;/b&gt;3evena1A2B30", $out);
 
    }
    
}
