<xsl:stylesheet version="2.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform">
    <xsl:output method="xml" version="1.0" encoding="UTF-8" indent="no"/>
    <xsl:preserve-space elements="p"/>
    
    <!-- Include it all into article element -->
    <xsl:template match="/">
        <article>
            <xsl:attribute name="doi">
                <xsl:value-of select="/article/front/article-meta/article-id[@pub-id-type='doi']"/>
            </xsl:attribute>
            <!-- Metadata for Abstract Page -->
            <abstract_meta>
                <abstract_authors>
                    <xsl:apply-templates select="/article/front/article-meta/contrib-group"/>
                </abstract_authors><xsl:text>&#x2029;</xsl:text>
                
                <abstract_title>
                    <xsl:value-of select="/article/front/article-meta/title-group/article-title"/>
                </abstract_title><xsl:text>&#x2029;</xsl:text>
                
                <abstract_keywords>
                    <xsl:text>keywords – </xsl:text>
                    <italic>
                    <xsl:for-each select="/article/front/article-meta/kwd-group/kwd">
                        <xsl:if test="position() != 1">, </xsl:if>
                        <xsl:value-of select="."/>
                    </xsl:for-each>
                    </italic>
                </abstract_keywords><xsl:text>&#x2029;</xsl:text>
            </abstract_meta>
            
            <!--Abstract story-->
            <abstract_main>
                <abstract>
                    <xsl:value-of select="/article/front/article-meta/abstract"/>
                </abstract><xsl:text>&#x2029;</xsl:text>
                
                <!-- DOI -->
                <doi>DOI: <xsl:value-of select="/article/front/article-meta/article-id[@pub-id-type='doi']"/></doi><xsl:text>&#x2029;</xsl:text>
            </abstract_main>
            
            <!-- Metadata for Article first page-->
            <article_meta>
                <title>
                    <xsl:value-of select="/article/front/article-meta/title-group/article-title"/>
                </title><xsl:text>&#x2029;</xsl:text>
                
                <authors>
                    <xsl:apply-templates select="/article/front/article-meta/contrib-group"/>
                </authors><xsl:text>&#x2029;</xsl:text>
            </article_meta>
            
            <!-- Body of the Article -->
            <body>
                <xsl:apply-templates select="/article/body"/>
            </body>
            <!-- Back of article with references and footnotes -->
            <back>
                <xsl:apply-templates select="/article/back"/>
            </back>
        </article>
    </xsl:template>
    
    <!-- Transform title into InDesign paragraph style "title" -->
    <xsl:template match="title-group/article-title">
        <title>
            <xsl:value-of select="."/>
        </title>
        <abstract_title>
            <xsl:value-of select="."/>
        </abstract_title>
    </xsl:template>
    
    <!-- Transform abstract into InDesign paragraph style "abstract" -->
    <xsl:template match="abstract">
        <abstract>
            <xsl:value-of select="."/>
        </abstract>
    </xsl:template>
    
    <!-- Template for contrib-group -->
    <xsl:template match="contrib-group">
        
        <xsl:apply-templates select="contrib[@contrib-type='author']"/>
        
    </xsl:template>
    
    <!-- Template for author -->
    <xsl:template match="contrib[@contrib-type='author']">
        <xsl:if test="position() > 1">
            <xsl:if test="position() = last()">
                <!-- Last author -->
                <xsl:text> &amp; </xsl:text>
            </xsl:if>
            <xsl:if test="position() != last()">
                <!-- Intermediate authors -->
                <xsl:text>, </xsl:text>
            </xsl:if>
        </xsl:if>
        
        <xsl:value-of select="name/given-names"/>
        <xsl:text> </xsl:text>
        <xsl:value-of select="name/surname"/>
        
    </xsl:template>
    <xsl:template match="kwd-group/kwd">
        <xsl:if test="position() != 1">
            <xsl:text>, </xsl:text>
        </xsl:if>
        <xsl:value-of select="."/>
    </xsl:template>
    
    
    <xsl:template match="article-meta">
        <abstract_meta>
            <!-- Apply templates to authors within contrib-group -->
            <abstract_authors>
                <xsl:apply-templates/>
            </abstract_authors>
            <xsl:text>&#x2029;</xsl:text>
            
            <!-- Apply templates to the article title -->
            <abstract_title>
                <xsl:apply-templates/>
            </abstract_title>
            <xsl:text>&#x2029;</xsl:text>
            
            <!-- Apply templates to keywords -->
            <abstract_keywords>
                <xsl:text>Keywords: </xsl:text>
                <xsl:apply-templates select="kwd-group/kwd"/>
            </abstract_keywords>
        </abstract_meta>
        <article_meta>
            <!-- Apply templates to the article title -->
            <title>
                <xsl:apply-templates/>
            </title>
            <xsl:text>&#x2029;</xsl:text>
            <!-- Apply templates to authors within contrib-group for article_meta -->
            <authors>
                <xsl:apply-templates/>
            </authors>
            <!-- Additional article_meta content here -->
        </article_meta>
    </xsl:template>
    
    <!-- Transform body into InDesign paragraph style "body"-->
    <xsl:template match="body">
        <body>
            <xsl:apply-templates/>
        </body>
    </xsl:template>     
    
    <xsl:template match="sec">
        <section>
            <xsl:apply-templates/>
        </section>
    </xsl:template>
    <!-- Template to match the chapter titles (h1) -->
    <xsl:template match="sec[@sec-type='chapter']/title"><h1><xsl:value-of select="."/></h1><xsl:text>&#x2029;</xsl:text></xsl:template>
    
    <!-- Template to match the section titles (h2) within chapters -->
    <xsl:template match="sec[@sec-type='chapter']/sec/title">
        <!-- Process h2 titles -->
        <h2><xsl:value-of select="."/></h2>
        <xsl:text>&#x2029;</xsl:text>
    </xsl:template> 
    <xsl:template match="sec/p">
        <p>
            <xsl:apply-templates/>
        </p>
        <xsl:text>&#x2029;</xsl:text>
    </xsl:template>
    
    <!-- Template to match the citations in article sections -->
    <xsl:template match="sec/disp-quote/p">
        <cite>
            <xsl:apply-templates/>
        </cite>
        <xsl:text>&#x2029;</xsl:text>
    </xsl:template>
    
    <!-- Template for xref elements with ref-type="fn" -->
    <xsl:template match="xref[@ref-type='fn']">
        <fnn><xsl:value-of select="."/></fnn>
    </xsl:template>
    
    
    <!-- Inline style matching -->
    <xsl:template match="italic">
        <i><xsl:apply-templates/></i>
    </xsl:template>
    <xsl:template match="bold">
        <b><xsl:apply-templates/></b>
    </xsl:template>
    <!-- Add other inline styles as needed -->
    
    <!-- Figure matching -->
    <xsl:template match="fig[@fig-type='content-image']">
        <figure><fig_title><fig_number><xsl:value-of select="label"/></fig_number><xsl:text> </xsl:text><xsl:apply-templates select="caption/title"/></fig_title><xsl:text>&#x2029;</xsl:text><fig_note><xsl:apply-templates select="caption/p"/></fig_note><xsl:text>&#x2029;</xsl:text></figure>
    </xsl:template>
    <!-- Tables matching -->
    <xsl:template match="table-wrap">
        <table>
            <!-- Optional: Process table caption -->
            <caption>
                <xsl:apply-templates select="caption"/>
            </caption>
            <!-- Process the table itself -->
            <xsl:apply-templates select="table"/>
        </table>
         <xsl:text>&#x2029;</xsl:text>
    </xsl:template>
    
    <xsl:template match="table">
        <xsl:apply-templates select="thead"/>
        <xsl:apply-templates select="tbody"/>
    </xsl:template>
    
    <xsl:template match="thead">
        <thead>
            <xsl:apply-templates select="tr"/>
        </thead>
    </xsl:template>
    
    <xsl:template match="tbody">
        <tbody>
            <xsl:apply-templates select="tr"/>
        </tbody>
    </xsl:template>
    
    <xsl:template match="tr">
        <tr>
            <xsl:apply-templates select="th|td"/>
        </tr>
    </xsl:template>
    
    <xsl:template match="th">
        <th>
            <xsl:apply-templates/>
        </th>
    </xsl:template>
    
    <xsl:template match="td">
        <td>
            <xsl:apply-templates/>
        </td>
    </xsl:template>
    
    <!-- Footnote matching -->
    <!-- Footnote group matching -->
    <xsl:template match="fn-group[@content-type='footnotes']">
        <footnotes><xsl:apply-templates select="fn"/></footnotes>
    </xsl:template>
    
    <!-- Individual footnote matching --><!-- Directly apply templates to the paragraph content without wrapping it in an additional element -->
    <xsl:template match="fn"><footnote><number><xsl:value-of select="label"/></number><xsl:text> </xsl:text><xsl:apply-templates select="p/node()"/></footnote><xsl:text>&#x2029;</xsl:text></xsl:template>
    <!-- Template for text nodes and inline elements within paragraph -->
    <xsl:template match="fn/p/text()"><xsl:value-of select="."/>
    </xsl:template>
    
    <!-- Process xref with ref-type="bibr" within footnotes to include only text content -->
    <xsl:template match="fn//xref[@ref-type='bibr']"><xsl:apply-templates/>
    </xsl:template>
    
</xsl:stylesheet>