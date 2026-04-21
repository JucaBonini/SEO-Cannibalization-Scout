<?php
namespace STSCannibal\Core;

class DeepAnalyzer {
    
    /**
     * Termos irrelevantes que diluem o SEO e causam canibalização falsa
     */
    public static function get_stop_words() {
        return [
            'aprenda-a-fazer','como-fazer','o-melhor','a-melhor','receita-de','receita','facil','simples',
            'caseiro','economico','rápido','rapido','super','passo-a-passo','dicas','segredo',
            'perfeito','inesquecível','inesquecivel','delicioso','deliciosa','com','para',
            'melhor','receitas','passo','como','fazer','preparar','jeito','fácil'
        ];
    }

    /**
     * Normalização profunda para encontrar a intenção de busca real
     * Remove acentos, stop-words, números e padroniza o texto.
     */
    public static function normalize($text) {
        $stops = self::get_stop_words();
        
        // Converte para minusculo e remove acentos
        $text = mb_strtolower($text, 'UTF-8');
        $text = str_replace(
            ['á','à','â','ã','ä','é','è','ê','ë','í','ì','î','ï','ó','ò','ô','õ','ö','ú','ù','û','ü','ç','-','_'],
            ['a','a','a','a','a','e','e','e','e','i','i','i','i','o','o','o','o','o','u','u','u','u','c',' ',' '],
            $text
        );
        
        // Remove anos e numerais comuns (2024, 2025...)
        $text = preg_replace('/[0-9]+/', '', $text);
        
        // Remove stop words individualmente
        foreach($stops as $stop) {
            $text = preg_replace('/\b'.preg_quote($stop, '/').'\b/', '', $text);
        }
        
        // Limpeza final de espaços canônicos
        $text = preg_replace('/\s+/', ' ', trim($text));
        
        return $text;
    }

    /**
     * Calcula a similaridade entre dois textos já normalizados
     */
    public static function get_similarity($text1, $text2) {
        if ($text1 === $text2) return 100;
        
        similar_text($text1, $text2, $percent);
        return $percent;
    }
}
