<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Contracts\View\View;

class HelpPdfController extends Controller
{
    public function index(): View
    {
        $hasPdf = file_exists(public_path('help/help.pdf'));
        return view('settings.help-pdf', compact('hasPdf'));
    }

    public function upload(Request $request): RedirectResponse
    {
        $request->validate([
            'help_pdf' => 'required|file|mimes:pdf|max:51200',
        ]);

        $dir = public_path('help');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $request->file('help_pdf')->move($dir, 'help.pdf');

        return redirect()->route('settings.help-pdf.index')
            ->with('success', 'Help PDF uploaded successfully.');
    }

    public function destroy(): RedirectResponse
    {
        $path = public_path('help/help.pdf');
        if (file_exists($path)) {
            unlink($path);
        }

        return redirect()->route('settings.help-pdf.index')
            ->with('success', 'Help PDF removed.');
    }
}
